<?php

namespace App\Models;

use App\Domain\Pak\Enums\PerformanceEvaluationStatus;
use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PerformanceEvaluation extends PakModel
{
    protected $attributes = ['status' => 'CURRENT'];

    protected function casts(): array
    {
        return [
            'evaluation_type' => PerformanceEvaluationType::class,
            'source_kind' => PerformanceSourceKind::class,
            'status' => PerformanceEvaluationStatus::class,
            'performance_year' => 'integer',
            'coverage_start' => 'immutable_date', 'coverage_end' => 'immutable_date',
            'source_date' => 'immutable_date', 'source_position_tmt' => 'immutable_date',
            'source_sk_date' => 'immutable_date', 'superseded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function positionHistory()
    {
        return $this->belongsTo(EmployeePositionHistory::class, 'employee_position_history_id');
    }

    public function predecessor()
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function successor()
    {
        return $this->hasOne(self::class, 'previous_version_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supersededByUser()
    {
        return $this->belongsTo(User::class, 'superseded_by_user_id');
    }

    public function importRows()
    {
        return $this->hasMany(PakSkpImportRow::class);
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (self $row) {
            Validator::make($row->getAttributes(), [
                'status' => ['required', Rule::enum(PerformanceEvaluationStatus::class)],
                'evaluation_type' => ['required', Rule::enum(PerformanceEvaluationType::class)],
                'source_kind' => ['required', Rule::enum(PerformanceSourceKind::class)],
                'source_identity_fingerprint' => ['required', 'regex:/\A[0-9a-f]{64}\z/'],
                'content_fingerprint' => ['required', 'regex:/\A[0-9a-f]{64}\z/'],
                'coverage_start' => ['required', 'date'],
                'coverage_end' => ['required', 'date', 'after_or_equal:coverage_start'],
                'performance_year' => ['required', 'integer'],
            ])->validate();

            $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);
            if (! $row->exists && $row->status !== PerformanceEvaluationStatus::CURRENT) {
                $fail('status', 'Evaluasi baru harus CURRENT.');
            }
            if ($row->exists) {
                $stored = $row->newQuery()->findOrFail($row->getKey());
                if ($stored->status !== PerformanceEvaluationStatus::CURRENT
                    || $row->status !== PerformanceEvaluationStatus::SUPERSEDED
                    || array_diff(array_keys($row->getDirty()), ['status', 'current_source_identity_fingerprint', 'superseded_by_user_id', 'superseded_at', 'updated_at'])) {
                    $fail('evaluation', 'Gunakan koreksi versioned; evaluasi immutable.');
                }
            }
            if ($row->status === PerformanceEvaluationStatus::CURRENT) {
                if ($row->current_source_identity_fingerprint !== $row->source_identity_fingerprint
                    || $row->superseded_by_user_id !== null || $row->superseded_at !== null) {
                    $fail('status', 'Metadata CURRENT tidak valid.');
                }
            } elseif ($row->current_source_identity_fingerprint !== null || $row->superseded_by_user_id === null || $row->superseded_at === null) {
                $fail('status', 'Metadata SUPERSEDED tidak valid.');
            }
            if ($row->source_kind === PerformanceSourceKind::OFFICIAL && trim((string) $row->source_reference) === '') {
                $fail('source_reference', 'Referensi resmi wajib diisi.');
            }
            if ($row->coverage_start->year !== $row->performance_year || $row->coverage_end->year !== $row->performance_year) {
                $fail('coverage_start', 'Coverage harus berada pada tahun kinerja.');
            }
            if ($row->employee_position_history_id !== null && ! $row->positionHistory()->where('person_id', $row->person_id)->exists()) {
                $fail('employee_position_history_id', 'Histori harus milik person yang sama.');
            }
            if ($row->previous_version_id === null) {
                if ($row->revision_reason !== null) {
                    $fail('revision_reason', 'Evaluasi awal tidak memiliki alasan revisi.');
                }
            } else {
                if (trim((string) $row->revision_reason) === '') {
                    $fail('revision_reason', 'Alasan revisi wajib diisi.');
                }
                if (! $row->exists) {
                    $previous = $row->predecessor()->first();
                    if (! $previous) {
                        $fail('previous_version_id', 'Predecessor tidak ditemukan.');
                    }
                    foreach (['person_id', 'source_kind', 'performance_year', 'evaluation_type', 'source_reference', 'source_record_identifier', 'source_identity_fingerprint'] as $field) {
                        $left = $row->getAttributes()[$field] ?? null;
                        $right = $previous->getAttributes()[$field] ?? null;
                        if (($left === null) !== ($right === null) || (string) $left !== (string) $right) {
                            $fail($field, 'Ordinary correction harus mempertahankan source identity.');
                        }
                    }
                    if ($row->content_fingerprint === $previous->content_fingerprint) {
                        $fail('content_fingerprint', 'NO_CHANGES: tidak ada perubahan content.');
                    }
                }
            }
        });
    }
}
