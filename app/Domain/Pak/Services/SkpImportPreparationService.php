<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization;
use App\Domain\Pak\Contracts\SkpSourceAdapter;
use App\Domain\Pak\DTOs\SkpSourceRowData;
use App\Domain\Pak\DTOs\SkpStoredSource;
use App\Domain\Pak\Enums\PerformanceEvaluationStatus;
use App\Domain\Pak\Enums\SkpImportBatchStatus as BatchStatus;
use App\Domain\Pak\Enums\SkpImportRowStatus as RowStatus;
use App\Domain\Pak\Enums\SkpPersonResolutionStatus as PersonStatus;
use App\Domain\Pak\Exceptions\SkpPreparationException;
use App\Domain\Pak\Exceptions\SkpSourceStorageException;
use App\Models\PakSkpImportBatch;
use App\Models\PakSkpImportRow;
use App\Models\PerformanceEvaluation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SkpImportPreparationService
{
    public function __construct(
        private SkpSourceStorage $storage,
        private SkpActiveSourceValidator $active,
        private SkpPersonMatcher $matcher,
        private SkpFingerprintSerializer $serializer,
    ) {}

    /** Consumes the caller's stream without closing it. Parsing reads the retained private copy. */
    public function prepare(User $actor, SkpSourceAdapter $adapter, mixed $stream, string $originalFilename, string $disk): PakSkpImportBatch
    {
        PakEmployeeAuthorization::authorize($actor, 'pak.skp.manage');
        $metadata = $this->adapterMetadata($adapter);
        $source = $this->storage->store($stream, $originalFilename, $disk);
        try {
            $batch = DB::transaction(fn () => PakSkpImportBatch::create(get_object_vars($source) + $metadata + [
                'created_by' => $actor->id, 'status' => BatchStatus::PARSING,
            ]));
        } catch (Throwable) {
            // Reference check fails closed, including an uncertain database commit outcome.
            if (! $this->storage->cleanupUnreferenced($source)) {
                throw new SkpSourceStorageException('SOURCE_COMPENSATION_REFERENCED', $source);
            }
            throw new SkpPreparationException('PERSISTENCE_FAILED');
        }

        return $this->prepareBatch($actor, $batch->id, $adapter);
    }

    /** Single-use preparation of an existing, empty PARSING batch. No evaluation writes. */
    public function prepareBatch(User $actor, int $batchId, SkpSourceAdapter $adapter): PakSkpImportBatch
    {
        PakEmployeeAuthorization::authorize($actor, 'pak.skp.manage');
        $metadata = $this->adapterMetadata($adapter);
        $batch = DB::transaction(function () use ($batchId, $metadata) {
            $batch = $this->lockedParsingBatch($batchId);
            if ($batch->rows()->exists() || $batch->only(array_keys($metadata)) !== $metadata) {
                throw new SkpPreparationException('PREPARATION_NOT_ALLOWED', $batchId);
            }

            return $batch;
        });
        $stream = null;
        $outcomes = [];
        $ordinals = [];
        $stage = 'SOURCE_STORAGE_READ_FAILED';
        try {
            $source = new SkpStoredSource($batch->disk, $batch->object_key, $batch->original_filename, $batch->checksum_sha256);
            if (! $this->storage->exists($source)) {
                throw new SkpPreparationException($stage, $batchId);
            }
            $stream = Storage::disk($batch->disk)->readStream($batch->object_key);
            if (! is_resource($stream)) {
                throw new SkpPreparationException($stage, $batchId);
            }
            $stage = 'SOURCE_ADAPTER_FAILED';
            // Adapter exceptions are distinct from row validation; no DB lock spans iterator advancement.
            try {
                $ordinal = 0;
                foreach ($adapter->read($stream) as $sourceRow) {
                    if (! $sourceRow instanceof SkpSourceRowData) {
                        throw new SkpPreparationException('SOURCE_PROCESSING_FAILED', $batchId);
                    }
                    $stage = 'PERSISTENCE_FAILED';
                    $row = $this->persistOccurrence($batchId, ++$ordinal, $sourceRow);
                    $ordinals[$row->id] = $ordinal;
                    $stage = 'SOURCE_PROCESSING_FAILED';
                    $outcomes[$row->id] = $this->classify($sourceRow);
                    $stage = 'SOURCE_ADAPTER_FAILED';
                }
            } catch (ValidationException $error) {
                if ($stage !== 'SOURCE_ADAPTER_FAILED') {
                    throw $error;
                }

                // Adapters may reject workbook structure using the existing validation contract.
                return $this->finishFailure($batchId, BatchStatus::INVALID, 'SOURCE_VALIDATION_FAILED');
            }
            $this->group($outcomes, $ordinals);
            $stage = 'PERSISTENCE_FAILED';
            foreach ($outcomes as $rowId => $attributes) {
                DB::transaction(function () use ($batchId, $rowId, $attributes) {
                    $batch = $this->lockedParsingBatch($batchId);
                    $row = $batch->rows()->findOrFail($rowId);
                    if ($row->processing_status !== RowStatus::PENDING) {
                        throw new SkpPreparationException('PREPARATION_NOT_ALLOWED', $batchId);
                    }
                    $row->update($attributes);
                });
            }

            return DB::transaction(function () use ($batchId) {
                $batch = $this->lockedParsingBatch($batchId);
                if ($batch->rows()->where('processing_status', RowStatus::PENDING->value)->exists()) {
                    throw new SkpPreparationException('PREPARATION_NOT_ALLOWED', $batchId);
                }
                $batch->update(['status' => BatchStatus::READY, 'summary' => $this->summary($batch)]);

                return $batch;
            });
        } catch (Throwable $error) {
            // Never overwrite a batch closed by another operation, or reset its rows.
            if ($error instanceof SkpPreparationException && $error->issue_code === 'PREPARATION_NOT_ALLOWED') {
                throw $error;
            }
            $code = $error instanceof SkpPreparationException || $error instanceof SkpSourceStorageException ? $error->issue_code
                : ($error instanceof QueryException ? 'PERSISTENCE_FAILED' : $stage);
            try {
                $this->finishFailure($batchId, BatchStatus::FAILED, $code);
            } catch (Throwable) {
                throw new SkpPreparationException('PERSISTENCE_FAILED', $batchId);
            }
            throw new SkpPreparationException($code, $batchId);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function adapterMetadata(SkpSourceAdapter $adapter): array
    {
        $values = ['adapter_key' => $adapter->key(), 'adapter_version' => $adapter->version(), 'schema_version' => $adapter->schemaVersion()];
        Validator::make($values, ['adapter_key' => ['required', 'string', 'max:100'],
            'adapter_version' => ['required', 'string', 'max:50'], 'schema_version' => ['required', 'string', 'max:50']])->validate();

        return $values;
    }

    private function lockedParsingBatch(int $id): PakSkpImportBatch
    {
        $batch = PakSkpImportBatch::query()->lockForUpdate()->findOrFail($id);
        if ($batch->status !== BatchStatus::PARSING) {
            throw new SkpPreparationException('PREPARATION_NOT_ALLOWED', $id);
        }

        return $batch;
    }

    private function persistOccurrence(int $batchId, int $ordinal, SkpSourceRowData $source): PakSkpImportRow
    {
        return DB::transaction(function () use ($batchId, $ordinal, $source) {
            $this->lockedParsingBatch($batchId);

            return PakSkpImportRow::create([
                'batch_id' => $batchId, 'record_ordinal' => $ordinal,
                'source_sheet' => $source->source_locator['sheet'] ?? null,
                'source_row' => $source->source_locator['row'] ?? null,
                'source_locator' => $source->source_locator,
                'raw_provenance' => ['adapter' => $source->raw_provenance,
                    'source' => array_diff_key(get_object_vars($source), ['raw_provenance' => true])],
                'source_reference' => $this->stagingString($source->source_reference),
                'source_record_identifier' => $this->stagingString($source->source_record_identifier),
            ]);
        });
    }

    /** Invalid oversized fields remain in adapter provenance, not truncated into a new identity. */
    private function stagingString(?string $value): ?string
    {
        return $value !== null && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= 255 ? $value : null;
    }

    private function classify(SkpSourceRowData $source): array
    {
        $values = ['processing_status' => RowStatus::INVALID, 'issue_codes' => [], 'performance_evaluation_id' => null];
        $issues = $this->active->issues($source);
        if ($issues !== [] || $source->validation_issues !== []) {
            $values['issue_codes'] = $issues === [] ? ['SOURCE_VALIDATION_FAILED'] : array_column($issues, 'code');

            return $values;
        }
        $person = $this->matcher->match($source->nip);
        $values += ['person_resolution_status' => $person->status, 'person_id' => $person->person_id, 'match_method' => $person->match_method];
        if ($person->status !== PersonStatus::MATCHED) {
            $values['issue_codes'] = [$person->issue_code];

            return $values;
        }
        try {
            $record = $this->serializer->normalize($source, $person->requirePersonId());
        } catch (ValidationException $error) {
            $identity = collect($error->errors())->flatten()->contains(fn ($message) => str_starts_with($message, 'SOURCE_IDENTITY_AMBIGUOUS:'));
            $values['processing_status'] = $identity ? RowStatus::IDENTITY_AMBIGUOUS : RowStatus::INVALID;
            $values['issue_codes'] = [$identity ? 'SOURCE_IDENTITY_AMBIGUOUS' : 'SOURCE_VALIDATION_FAILED'];

            return $values;
        }
        $identity = $this->serializer->sourceIdentityFingerprint($record);
        $content = $this->serializer->contentFingerprint($record);
        $payload = json_decode($this->serializer->contentPayload($record), true, flags: JSON_THROW_ON_ERROR);
        $values = array_replace($values, [
            'source_reference' => $record->source_reference, 'source_record_identifier' => $record->source_record_identifier,
            'source_identity_fingerprint' => $identity, 'content_fingerprint' => $content,
            'normalized_payload' => ['record' => $payload, 'stable_identifier_evidence' => get_object_vars($record->stable_identifier_evidence)],
            'processing_status' => RowStatus::READY,
        ]);
        $current = PerformanceEvaluation::query()->useWritePdo()->where('status', PerformanceEvaluationStatus::CURRENT->value)
            ->where('current_source_identity_fingerprint', $identity)->first();
        if ($current === null) {
            return $values;
        }
        // Compare canonical values too: matching hashes must not hide corrupted persisted content.
        $currentValues = [];
        foreach ($payload as $field => $value) {
            if (in_array($field, ['schema_version', 'source_schema_version', 'person_id'], true)) {
                continue;
            }
            $currentValues[$field] = $current->$field instanceof \DateTimeInterface ? $current->$field->format('Y-m-d') : $current->$field;
        }
        try {
            $existing = $this->serializer->normalize(new SkpSourceRowData(...($currentValues + ['stable_identifier_evidence' => $record->stable_identifier_evidence])), (int) $current->person_id);
        } catch (ValidationException) {
            throw new SkpPreparationException('FINGERPRINT_PAYLOAD_MISMATCH');
        }
        if ($this->serializer->sourceIdentityPayload($existing) !== $this->serializer->sourceIdentityPayload($record)
            || $this->serializer->contentFingerprint($existing) !== $current->content_fingerprint) {
            throw new SkpPreparationException('FINGERPRINT_PAYLOAD_MISMATCH');
        }
        if ($current->content_fingerprint === $content) {
            if ($this->serializer->contentPayload($existing) !== $this->serializer->contentPayload($record)) {
                throw new SkpPreparationException('FINGERPRINT_PAYLOAD_MISMATCH');
            }
            $values['processing_status'] = RowStatus::DUPLICATE;
            $values['performance_evaluation_id'] = $current->id;
        } else {
            $values['processing_status'] = RowStatus::CONFLICT;
            $values['issue_codes'] = ['SOURCE_CONTENT_CONFLICT'];
        }

        return $values;
    }

    private function group(array &$outcomes, array $ordinals): void
    {
        $groups = [];
        foreach ($outcomes as $id => $values) {
            if (isset($values['source_identity_fingerprint'])) {
                $groups[$values['source_identity_fingerprint']][] = $id;
            }
        }
        foreach ($groups as $ids) {
            $memberOrdinals = array_map(fn ($id) => $ordinals[$id], $ids);
            sort($memberOrdinals, SORT_NUMERIC);
            $first = $outcomes[$ids[0]];
            $ambiguous = false;
            $conflict = false;
            $allReady = true;
            foreach ($ids as $id) {
                $allReady = $allReady && $outcomes[$id]['processing_status'] === RowStatus::READY;
                $ambiguous = $ambiguous || $outcomes[$id]['normalized_payload']['stable_identifier_evidence'] !== $first['normalized_payload']['stable_identifier_evidence'];
                $conflict = $conflict || $outcomes[$id]['normalized_payload']['record'] !== $first['normalized_payload']['record'];
            }
            foreach ($ids as $id) {
                if ($allReady && ! $ambiguous && ! $conflict) {
                    $outcomes[$id]['normalized_payload']['preparation_group'] = [
                        'representative_ordinal' => $memberOrdinals[0], 'occurrence_ordinals' => $memberOrdinals,
                    ];
                }
                if ($ambiguous || $conflict) {
                    $outcomes[$id]['processing_status'] = $ambiguous ? RowStatus::IDENTITY_AMBIGUOUS : RowStatus::CONFLICT;
                    $outcomes[$id]['issue_codes'] = [$ambiguous ? 'SOURCE_IDENTITY_AMBIGUOUS' : 'SOURCE_CONTENT_CONFLICT'];
                    $outcomes[$id]['performance_evaluation_id'] = null;
                    if ($ambiguous) {
                        $outcomes[$id]['source_identity_fingerprint'] = null;
                        $outcomes[$id]['content_fingerprint'] = null;
                        $outcomes[$id]['normalized_payload'] = null;
                    }
                }
                // Group metadata is provisional and outside canonical record/hash inputs.
                // Commit must regroup/reclassify; no representative outcome is applied here.
            }
        }
    }

    private function finishFailure(int $batchId, BatchStatus $status, string $code): PakSkpImportBatch
    {
        return DB::transaction(function () use ($batchId, $status, $code) {
            $batch = $this->lockedParsingBatch($batchId);
            foreach ($batch->rows()->where('processing_status', RowStatus::PENDING->value)->get() as $row) {
                $row->update(['processing_status' => $status === BatchStatus::INVALID ? RowStatus::INVALID : RowStatus::FAILED,
                    'issue_codes' => [$code], 'error_metadata' => ['code' => $code]]);
            }
            $batch->update(['status' => $status, 'summary' => $this->summary($batch), 'error_metadata' => ['code' => $code]]);

            return $batch;
        });
    }

    private function summary(PakSkpImportBatch $batch): array
    {
        $counts = array_fill_keys(array_column(RowStatus::cases(), 'value'), 0);
        foreach ($batch->rows()->get(['processing_status']) as $row) {
            $counts[$row->processing_status->value]++;
        }

        return ['total' => array_sum($counts), 'outcomes' => $counts];
    }
}
