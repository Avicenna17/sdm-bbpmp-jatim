<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization;
use App\Domain\Pak\DTOs\NormalizedSkpRecord;
use App\Domain\Pak\DTOs\SkpSourceIdentityEvidence;
use App\Domain\Pak\DTOs\SkpSourceRowData;
use App\Domain\Pak\Enums\PerformanceEvaluationStatus;
use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;
use App\Domain\Pak\Enums\SkpPersonResolutionStatus;
use App\Models\EmployeePositionHistory;
use App\Models\PakSkpImportRow;
use App\Models\PerformanceEvaluation;
use App\Models\User;
use App\Support\Database\PersonLocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;
use TypeError;
use ValueError;

final class PerformanceEvaluationService
{
    public function __construct(private SkpFingerprintSerializer $serializer, private PakAuditService $audit, private SkpActiveSourceValidator $active, private SkpCommitRetryPolicy $retry) {}

    /** Same connection/nested Laravel transaction: the future caller owns the outer batch commit. */
    public function createInitial(User $actor, NormalizedSkpRecord $record, ?int $historyId = null): PerformanceEvaluation
    {
        PakEmployeeAuthorization::authorize($actor, 'pak.skp.manage');
        $this->active->validate(new SkpSourceRowData(...array_diff_key(get_object_vars($record), ['person_id' => true])));
        $attributes = $this->attributes($record);

        return DB::transaction(function () use ($actor, $record, $attributes, $historyId) {
            PersonLocks::acquire([$record->person_id]);
            $this->validateHistory($historyId, $record->person_id);

            // No read-before-create dedup or collision translation. The unique index remains authoritative.
            return PerformanceEvaluation::create($attributes + [
                'created_by' => $actor->id, 'employee_position_history_id' => $historyId,
                'previous_version_id' => null, 'revision_reason' => null, 'historical_reason' => null,
            ]);
        });
    }

    public function correct(User $actor, int $predecessorId, NormalizedSkpRecord $proposed, string $revisionReason, int $sourceRowId, ?int $historyId = null): PerformanceEvaluation
    {
        PakEmployeeAuthorization::authorize($actor, 'pak.skp.manage');
        if (DB::transactionLevel() !== 0) {
            $this->reject('CORRECTION_REQUIRES_TOP_LEVEL_TRANSACTION');
        }
        if (! mb_check_encoding($revisionReason, 'UTF-8') || ! preg_match('/[^\p{Z}\s]/u', $revisionReason)) {
            throw ValidationException::withMessages(['revision_reason' => 'REVISION_REASON_REQUIRED']);
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $this->correctAttempt($actor, $predecessorId, $proposed, $revisionReason, $sourceRowId, $historyId);
            } catch (Throwable $error) {
                if (! $this->retry->allowsDeadlock($error)) {
                    throw $error;
                }
                if ($attempt === 3) {
                    $this->reject('CONCURRENCY_RETRY_EXHAUSTED');
                }
            }
        }
    }

    private function correctAttempt(User $actor, int $predecessorId, NormalizedSkpRecord $proposed, string $revisionReason, int $sourceRowId, ?int $historyId): PerformanceEvaluation
    {
        // Each attempt reloads the original target and provenance in a fresh top-level transaction.
        return DB::transaction(function () use ($actor, $predecessorId, $proposed, $revisionReason, $sourceRowId, $historyId) {
            // Routing only. The original predecessor is reloaded/current-validated after Person.
            $personId = PerformanceEvaluation::query()->findOrFail($predecessorId)->person_id;
            PersonLocks::acquire([$personId]);
            $previous = PerformanceEvaluation::query()->lockForUpdate()->findOrFail($predecessorId);
            if ((int) $previous->person_id !== (int) $personId) {
                $this->reject('SOURCE_IDENTITY_CHANGE_NOT_ALLOWED');
            }
            if ($previous->status !== PerformanceEvaluationStatus::CURRENT) {
                $this->reject('PREDECESSOR_NOT_CURRENT');
            }
            $identity = ['person_id' => (int) $previous->person_id, 'source_kind' => $previous->source_kind,
                'performance_year' => $previous->performance_year, 'evaluation_type' => $previous->evaluation_type,
                'source_reference' => $previous->source_reference, 'source_record_identifier' => $previous->source_record_identifier];
            foreach ($identity as $field => $value) {
                if ($value !== $proposed->$field) {
                    $this->reject('SOURCE_IDENTITY_CHANGE_NOT_ALLOWED');
                }
            }
            $attributes = $this->attributes($proposed);
            if ($attributes['source_identity_fingerprint'] !== $previous->source_identity_fingerprint) {
                $this->reject('SOURCE_IDENTITY_CHANGE_NOT_ALLOWED');
            }
            if ($attributes['content_fingerprint'] === $previous->content_fingerprint) {
                $this->reject('NO_CHANGES');
            }
            $this->validateHistory($historyId, $proposed->person_id);
            $sourceRow = PakSkpImportRow::query()->sharedLock()->findOrFail($sourceRowId);
            $this->validateProvenance($sourceRow, $proposed, $attributes);

            $previous->update(['status' => PerformanceEvaluationStatus::SUPERSEDED,
                'current_source_identity_fingerprint' => null, 'superseded_by_user_id' => $actor->id, 'superseded_at' => now()]);
            $successor = PerformanceEvaluation::create($attributes + [
                'created_by' => $actor->id, 'previous_version_id' => $previous->id,
                'revision_reason' => $revisionReason, 'historical_reason' => null, 'employee_position_history_id' => $historyId,
            ]);
            $this->audit->record($actor, $successor, 'PERFORMANCE_EVALUATION_CORRECTED', [
                'before' => ['id' => $previous->id, 'status' => PerformanceEvaluationStatus::CURRENT->value],
                'after' => ['id' => $successor->id, 'status' => PerformanceEvaluationStatus::CURRENT->value, 'source_row_id' => $sourceRow->id],
                'reason' => $revisionReason, 'supersedes_id' => $previous->id,
            ]);

            return $successor;
        });
    }

    /** Only the serializer creates canonical values/hashes; it rejects a forged non-canonical DTO. */
    private function attributes(NormalizedSkpRecord $record): array
    {
        $values = json_decode($this->serializer->contentPayload($record), true, flags: JSON_THROW_ON_ERROR);
        unset($values['schema_version'], $values['source_schema_version']);
        $identity = $this->serializer->sourceIdentityFingerprint($record);

        return $values + ['source_identity_fingerprint' => $identity,
            'content_fingerprint' => $this->serializer->contentFingerprint($record),
            'current_source_identity_fingerprint' => $identity, 'status' => PerformanceEvaluationStatus::CURRENT,
            'superseded_by_user_id' => null, 'superseded_at' => null];
    }

    private function validateHistory(?int $historyId, int $personId): void
    {
        if ($historyId !== null && EmployeePositionHistory::query()->whereKey($historyId)->where('person_id', $personId)->sharedLock()->first() === null) {
            throw ValidationException::withMessages(['employee_position_history_id' => 'Histori harus milik person yang sama.']);
        }
    }

    private function validateProvenance(PakSkpImportRow $row, NormalizedSkpRecord $proposed, array $attributes): void
    {
        if ($row->person_resolution_status !== SkpPersonResolutionStatus::MATCHED || $row->person_id === null
            || (int) $row->person_id !== $proposed->person_id
            || $row->source_reference !== $proposed->source_reference || $row->source_record_identifier !== $proposed->source_record_identifier
            || $row->source_identity_fingerprint !== $attributes['source_identity_fingerprint']
            || $row->content_fingerprint !== $attributes['content_fingerprint']) {
            $this->reject('FINGERPRINT_PAYLOAD_MISMATCH');
        }
        $payload = $row->normalized_payload;
        $expected = json_decode($this->serializer->contentPayload($proposed), true, flags: JSON_THROW_ON_ERROR);
        $record = $payload['record'] ?? null;
        $evidence = $payload['stable_identifier_evidence'] ?? null;
        if (! is_array($record) || ! is_array($evidence)
            || count($record) !== count($expected) || array_diff_key($expected, $record)
            || count($evidence) !== 3 || array_diff(['strategy_key', 'component_reference', 'component_value'], array_keys($evidence))) {
            $this->reject('FINGERPRINT_PAYLOAD_MISMATCH');
        }
        try {
            $values = $record;
            unset($values['schema_version'], $values['source_schema_version'], $values['person_id']);
            $values['source_kind'] = PerformanceSourceKind::from($values['source_kind']);
            $values['evaluation_type'] = PerformanceEvaluationType::from($values['evaluation_type']);
            $values['stable_identifier_evidence'] = new SkpSourceIdentityEvidence(...$evidence);
            $reconstructed = $this->serializer->normalize(new SkpSourceRowData(...$values), $record['person_id']);
            $canonical = json_decode($this->serializer->contentPayload($reconstructed), true, flags: JSON_THROW_ON_ERROR);
        } catch (ValidationException|TypeError|ValueError) {
            $this->reject('FINGERPRINT_PAYLOAD_MISMATCH');
        }
        // MySQL JSON may reorder object keys: compare strict values, not PHP array insertion order.
        foreach ($canonical as $field => $value) {
            if ($record[$field] !== $value || $expected[$field] !== $value) {
                $this->reject('FINGERPRINT_PAYLOAD_MISMATCH');
            }
        }
        foreach (get_object_vars($reconstructed->stable_identifier_evidence) as $field => $value) {
            if ($evidence[$field] !== $value) {
                $this->reject('FINGERPRINT_PAYLOAD_MISMATCH');
            }
        }
        if ($this->serializer->sourceIdentityFingerprint($reconstructed) !== $row->source_identity_fingerprint
            || $this->serializer->contentFingerprint($reconstructed) !== $row->content_fingerprint) {
            $this->reject('FINGERPRINT_PAYLOAD_MISMATCH');
        }
        // No row-status whitelist: validated conflict/committed evidence is read-only provenance.
    }

    private function reject(string $code): never
    {
        throw ValidationException::withMessages(['evaluation' => $code]);
    }
}
