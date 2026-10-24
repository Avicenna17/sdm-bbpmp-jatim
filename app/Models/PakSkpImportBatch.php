<?php

namespace App\Models;

use App\Domain\Pak\Enums\SkpImportBatchStatus;
use App\Domain\Pak\Enums\SkpImportRowStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PakSkpImportBatch extends PakModel
{
    protected $attributes = ['status' => 'PARSING'];

    protected $hidden = ['disk', 'object_key', 'error_metadata'];

    protected function casts(): array
    {
        return ['status' => SkpImportBatchStatus::class, 'summary' => 'array', 'error_metadata' => 'array',
            'committed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function rows()
    {
        return $this->hasMany(PakSkpImportRow::class, 'batch_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function committer()
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (self $batch) {
            Validator::make($batch->getAttributes(), [
                'disk' => ['required', 'string', 'max:50'],
                'checksum_sha256' => ['required', 'regex:/\A[0-9a-f]{64}\z/'],
                'status' => ['required', Rule::enum(SkpImportBatchStatus::class)],
            ])->validate();
            if ($batch->exists && ($batch->newQuery()->findOrFail($batch->getKey())->status === SkpImportBatchStatus::COMMITTED
                || array_diff(array_keys($batch->getDirty()), ['status', 'committed_by', 'committed_at', 'summary', 'error_metadata', 'updated_at']))) {
                throw ValidationException::withMessages(['batch' => 'Provenance dan batch COMMITTED immutable.']);
            }
            if ($batch->status === SkpImportBatchStatus::COMMITTED) {
                if ($batch->committed_by === null || $batch->committed_at === null) {
                    throw ValidationException::withMessages(['status' => 'COMMITTED membutuhkan actor dan waktu commit.']);
                }
                if ($batch->exists && $batch->rows()->whereIn('processing_status', [SkpImportRowStatus::PENDING->value, SkpImportRowStatus::READY->value])->exists()) {
                    throw ValidationException::withMessages(['status' => 'Batch masih memiliki row PENDING/READY.']);
                }
            } elseif ($batch->committed_by !== null || $batch->committed_at !== null) {
                throw ValidationException::withMessages(['status' => 'Metadata commit hanya untuk COMMITTED.']);
            }
        });
    }
}
