<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization;
use App\Domain\Pak\DTOs\NormalizedSkpRecord;
use App\Domain\Pak\DTOs\SkpSourceIdentityEvidence;
use App\Domain\Pak\DTOs\SkpSourceRowData;
use App\Domain\Pak\DTOs\SkpStoredSource;
use App\Domain\Pak\Enums\PerformanceEvaluationStatus;
use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;
use App\Domain\Pak\Enums\SkpImportBatchStatus as BatchStatus;
use App\Domain\Pak\Enums\SkpImportRowStatus as RowStatus;
use App\Domain\Pak\Enums\SkpPersonResolutionStatus;
use App\Domain\Pak\Exceptions\SkpCommitException;
use App\Models\PakSkpImportBatch;
use App\Models\PakSkpImportRow;
use App\Models\PerformanceEvaluation;
use App\Models\User;
use App\Support\Database\PersonLocks;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;
use TypeError;
use ValueError;

final class SkpImportCommitService
{
    public function __construct(
        private SkpSourceStorage $storage,
        private SkpFingerprintSerializer $serializer,
        private SkpPersonMatcher $matcher,
        private SkpActiveSourceValidator $active,
        private PerformanceEvaluationService $evaluations,
        private PakAuditService $audit,
        private SkpCommitRetryPolicy $retry,
    ) {}

    public function commit(User $actor, int $batchId): PakSkpImportBatch
    {
        PakEmployeeAuthorization::authorize($actor, 'pak.skp.manage');
        // A retry must start a fresh database transaction, not a savepoint inside a caller snapshot.
        if (DB::transactionLevel() !== 0) {
            throw new SkpCommitException('COMMIT_REQUIRES_TOP_LEVEL_TRANSACTION', $batchId);
        }
        $batch = PakSkpImportBatch::query()->useWritePdo()->findOrFail($batchId);
        if ($batch->status === BatchStatus::COMMITTED) {
            return $batch;
        }
        $this->ready($batch);
        $this->storage->verify(new SkpStoredSource($batch->disk, $batch->object_key, $batch->original_filename, $batch->checksum_sha256));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(fn () => $this->attempt($actor, $batchId));
            } catch (Throwable $error) {
                $retryable = $this->retry->allows($error);
                if ($retryable && $attempt < 3) {
                    continue;
                }
                $code = $retryable ? 'CONCURRENCY_RETRY_EXHAUSTED'
                    : ($error instanceof SkpCommitException ? $error->issue_code : 'PERSISTENCE_FAILED');
                // Rows retain their pre-attempt outcomes. No undocumented row failure transition.
                try {
                    $final = DB::transaction(function () use ($batchId, $code) {
                        $batch = PakSkpImportBatch::query()->lockForUpdate()->findOrFail($batchId);
                        if ($batch->status === BatchStatus::READY) {
                            $batch->update(['status' => BatchStatus::FAILED, 'error_metadata' => ['code' => $code]]);
                        }

                        return $batch;
                    });
                } catch (Throwable) {
                    throw new SkpCommitException('PERSISTENCE_FAILED', $batchId);
                }
                if ($final->status === BatchStatus::COMMITTED) {
                    return $final;
                }
                throw new SkpCommitException($code, $batchId);
            }
        }
    }

    private function ready(PakSkpImportBatch $batch): void
    {
        if ($batch->status !== BatchStatus::READY) {
            throw new SkpCommitException('COMMIT_NOT_ALLOWED', $batch->id);
        }
    }

    private function attempt(User $actor, int $batchId): PakSkpImportBatch
    {
        $batch = PakSkpImportBatch::query()->lockForUpdate()->findOrFail($batchId);
        if ($batch->status === BatchStatus::COMMITTED) {
            return $batch;
        }
        $this->ready($batch);
        $rows = $batch->rows()->orderBy('record_ordinal')->sharedLock()->get();
        $people = PersonLocks::acquire($rows->filter(fn ($row) => in_array($row->processing_status, [RowStatus::READY, RowStatus::DUPLICATE, RowStatus::CONFLICT], true))->pluck('person_id')->filter()->all());
        $groups = [];
        foreach ($rows as $row) {
            if (in_array($row->processing_status, [RowStatus::PENDING, RowStatus::IMPORTED], true)) {
                throw new SkpCommitException('FINGERPRINT_PAYLOAD_MISMATCH', $batchId);
            }
            if (in_array($row->processing_status, [RowStatus::READY, RowStatus::DUPLICATE, RowStatus::CONFLICT], true)) {
                $record = $this->reconstruct($row, $people);
                $groups[$this->serializer->sourceIdentityFingerprint($record)][] = ['row' => $row, 'record' => $record];
            }
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as $identity => $members) {
            $this->commitGroup($actor, $identity, $members);
        }
        $counts = array_fill_keys(array_column(RowStatus::cases(), 'value'), 0);
        foreach ($batch->rows()->get() as $row) {
            $counts[$row->processing_status->value]++;
        }
        if ($counts['READY'] !== 0 || $counts['PENDING'] !== 0) {
            throw new SkpCommitException('FINGERPRINT_PAYLOAD_MISMATCH', $batchId);
        }
        $summary = ['total' => array_sum($counts), 'outcomes' => $counts];
        $batch->update(['status' => BatchStatus::COMMITTED, 'committed_by' => $actor->id, 'committed_at' => now(), 'summary' => $summary]);
        $this->audit->record($actor, $batch, 'SKP_IMPORT_COMMITTED', [
            'before' => ['id' => $batchId, 'status' => BatchStatus::READY->value],
            'after' => ['id' => $batchId, 'status' => BatchStatus::COMMITTED->value, 'summary' => $summary],
        ]);

        return $batch->refresh();
    }

    private function reconstruct(PakSkpImportRow $row, Collection $people): NormalizedSkpRecord
    {
        try {
            $raw = $row->raw_provenance['source'] ?? null;
            $shape = array_diff_key(get_object_vars(new SkpSourceRowData), ['raw_provenance' => true]);
            if (! is_array($raw) || array_diff_key($raw, $shape) || array_diff_key($shape, $raw)) {
                $this->integrity($row);
            }
            $raw['source_kind'] = PerformanceSourceKind::from($raw['source_kind']);
            $raw['evaluation_type'] = PerformanceEvaluationType::from($raw['evaluation_type']);
            $raw['stable_identifier_evidence'] = $this->evidence($raw['stable_identifier_evidence'], $row);
            $source = new SkpSourceRowData(...$raw);
            $this->active->validate($source);
            $person = $this->matcher->matchCoordinated($source->nip, $people);
            if ($person->status !== SkpPersonResolutionStatus::MATCHED || $row->person_resolution_status !== SkpPersonResolutionStatus::MATCHED
                || $person->person_id !== $row->person_id || $row->match_method !== $person->match_method) {
                $this->integrity($row);
            }
            $record = $this->serializer->normalize($source, $person->requirePersonId());
            $canonical = json_decode($this->serializer->contentPayload($record), true, flags: JSON_THROW_ON_ERROR);
            if (! $this->sameFields($canonical, $row->normalized_payload['record'] ?? null)
                || ! $this->sameFields(get_object_vars($record->stable_identifier_evidence), $row->normalized_payload['stable_identifier_evidence'] ?? null)
                || $row->source_reference !== $record->source_reference || $row->source_record_identifier !== $record->source_record_identifier
                || $row->source_identity_fingerprint !== $this->serializer->sourceIdentityFingerprint($record)
                || $row->content_fingerprint !== $this->serializer->contentFingerprint($record)) {
                $this->integrity($row);
            }

            return $record;
        } catch (ValidationException|TypeError|ValueError) {
            $this->integrity($row);
        }
    }

    private function evidence(mixed $values, PakSkpImportRow $row): SkpSourceIdentityEvidence
    {
        if (! is_array($values) || count($values) !== 3 || array_diff(['strategy_key', 'component_reference', 'component_value'], array_keys($values))) {
            $this->integrity($row);
        }

        return new SkpSourceIdentityEvidence(...$values);
    }

    private function sameFields(array $expected, mixed $actual): bool
    {
        if (! is_array($actual) || count($expected) !== count($actual)) {
            return false;
        }
        foreach ($expected as $field => $value) {
            if (! array_key_exists($field, $actual) || $actual[$field] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function integrity(PakSkpImportRow $row): never
    {
        throw new SkpCommitException('FINGERPRINT_PAYLOAD_MISMATCH', $row->batch_id);
    }

    private function commitGroup(User $actor, string $identity, array $members): void
    {
        usort($members, fn ($a, $b) => $a['row']->record_ordinal <=> $b['row']->record_ordinal);
        $first = $members[0]['record'];
        $ambiguous = false;
        $conflict = false;
        foreach ($members as $member) {
            $ambiguous = $ambiguous || ! $this->sameFields(get_object_vars($first->stable_identifier_evidence), get_object_vars($member['record']->stable_identifier_evidence));
            $conflict = $conflict || $this->serializer->contentPayload($first) !== $this->serializer->contentPayload($member['record']);
        }
        $current = PerformanceEvaluation::query()->where('current_source_identity_fingerprint', $identity)->lockForUpdate()->first();
        if ($current !== null) {
            $existing = $this->currentRecord($current, $first, $members[0]['row']);
            $conflict = $conflict || $this->serializer->contentPayload($existing) !== $this->serializer->contentPayload($first);
        }
        $created = false;
        if (! $ambiguous && ! $conflict && $current === null) {
            $current = $this->evaluations->createInitial($actor, $first);
            $created = true;
        }
        foreach ($members as $index => $member) {
            $row = $member['row'];
            $status = $ambiguous ? RowStatus::IDENTITY_AMBIGUOUS : ($conflict ? RowStatus::CONFLICT
                : ($created && $index === 0 ? RowStatus::IMPORTED : RowStatus::DUPLICATE));
            $payload = $row->normalized_payload;
            unset($payload['preparation_group']);
            $changes = ['processing_status' => $status, 'performance_evaluation_id' => $ambiguous || $conflict ? null : $current->id,
                'issue_codes' => $ambiguous ? ['SOURCE_IDENTITY_AMBIGUOUS'] : ($conflict ? ['SOURCE_CONTENT_CONFLICT'] : []),
                'normalized_payload' => $ambiguous ? null : $payload];
            if ($ambiguous) {
                $changes += ['source_identity_fingerprint' => null, 'content_fingerprint' => null];
            }
            $row->update($changes);
        }
    }

    private function currentRecord(PerformanceEvaluation $current, NormalizedSkpRecord $incoming, PakSkpImportRow $row): NormalizedSkpRecord
    {
        if ($current->status !== PerformanceEvaluationStatus::CURRENT) {
            $this->integrity($row);
        }
        $values = array_diff_key(get_object_vars($incoming), ['person_id' => true, 'stable_identifier_evidence' => true]);
        foreach ($values as $field => $_) {
            $value = $current->$field;
            $values[$field] = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
        }
        try {
            $record = $this->serializer->normalize(new SkpSourceRowData(...($values + ['stable_identifier_evidence' => $incoming->stable_identifier_evidence])), (int) $current->person_id);
        } catch (ValidationException|TypeError|ValueError) {
            $this->integrity($row);
        }
        if ($this->serializer->sourceIdentityPayload($record) !== $this->serializer->sourceIdentityPayload($incoming)
            || $this->serializer->sourceIdentityFingerprint($record) !== $current->source_identity_fingerprint
            || $this->serializer->contentFingerprint($record) !== $current->content_fingerprint) {
            $this->integrity($row);
        }

        return $record;
    }
}
