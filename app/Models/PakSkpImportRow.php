<?php

namespace App\Models;

use App\Domain\Pak\Enums\SkpImportBatchStatus;
use App\Domain\Pak\Enums\SkpImportRowStatus;
use App\Domain\Pak\Enums\SkpPersonResolutionStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PakSkpImportRow extends PakModel
{
    protected $attributes = ['processing_status' => 'PENDING', 'person_resolution_status' => 'PENDING'];

    protected $hidden = ['raw_provenance', 'normalized_payload', 'error_metadata'];

    protected function casts(): array
    {
        return ['processing_status' => SkpImportRowStatus::class, 'person_resolution_status' => SkpPersonResolutionStatus::class,
            'record_ordinal' => 'integer', 'source_row' => 'integer', 'source_locator' => 'array',
            'normalized_payload' => 'array', 'raw_provenance' => 'array', 'issue_codes' => 'array', 'error_metadata' => 'array',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function batch()
    {
        return $this->belongsTo(PakSkpImportBatch::class, 'batch_id');
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function performanceEvaluation()
    {
        return $this->belongsTo(PerformanceEvaluation::class);
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (self $row) {
            Validator::make($row->getAttributes(), [
                'processing_status' => ['required', Rule::enum(SkpImportRowStatus::class)],
                'person_resolution_status' => ['required', Rule::enum(SkpPersonResolutionStatus::class)],
                'record_ordinal' => ['required', 'integer', 'min:1'],
                'source_row' => ['nullable', 'integer', 'min:1'],
                'source_identity_fingerprint' => [$row->source_identity_fingerprint === null ? 'nullable' : 'required', 'regex:/\A[0-9a-f]{64}\z/'],
                'content_fingerprint' => [$row->content_fingerprint === null ? 'nullable' : 'required', 'regex:/\A[0-9a-f]{64}\z/'],
            ])->validate();
            if ($row->exists && array_diff(array_keys($row->getDirty()), ['person_id', 'person_resolution_status', 'match_method', 'source_reference', 'source_record_identifier', 'source_identity_fingerprint', 'content_fingerprint', 'normalized_payload', 'processing_status', 'issue_codes', 'error_metadata', 'performance_evaluation_id', 'updated_at'])) {
                throw ValidationException::withMessages(['row' => 'Provenance awal immutable.']);
            }
            // Always query persisted batch state, never trust a previously loaded relation.
            $batch = $row->batch()->firstOrFail();
            if ($batch->status === SkpImportBatchStatus::COMMITTED) {
                throw ValidationException::withMessages(['batch_id' => 'Batch COMMITTED tertutup untuk create/update row.']);
            }
            $resolved = in_array($row->processing_status, [SkpImportRowStatus::READY, SkpImportRowStatus::IMPORTED, SkpImportRowStatus::DUPLICATE, SkpImportRowStatus::CONFLICT], true);
            if ($resolved && ($row->person_resolution_status !== SkpPersonResolutionStatus::MATCHED || $row->person_id === null
                || trim((string) $row->source_reference) === '' || $row->source_identity_fingerprint === null || $row->content_fingerprint === null)) {
                throw ValidationException::withMessages(['row' => 'Row siap membutuhkan person MATCHED, referensi, dan kedua fingerprint.']);
            }
            if (($row->person_resolution_status === SkpPersonResolutionStatus::MATCHED) !== ($row->person_id !== null)) {
                throw ValidationException::withMessages(['person_id' => 'Person hanya dapat diklaim oleh resolution MATCHED.']);
            }
            if (in_array($row->processing_status, [SkpImportRowStatus::IMPORTED, SkpImportRowStatus::DUPLICATE], true) && $row->performance_evaluation_id === null) {
                throw ValidationException::withMessages(['performance_evaluation_id' => 'Evaluation wajib untuk IMPORTED/DUPLICATE.']);
            }
            if ($row->performance_evaluation_id !== null && ! $row->performanceEvaluation()->where('person_id', $row->person_id)->exists()) {
                throw ValidationException::withMessages(['performance_evaluation_id' => 'Evaluation harus milik person yang sama.']);
            }
        });
    }
}
