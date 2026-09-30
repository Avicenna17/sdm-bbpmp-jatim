<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $guarded = ['id'];

    public function getReviewIssuesAttribute()
    {
        return $this->issues->reject(fn ($issue) => $issue->severity === 'INFO'
            || ($this->source_type === 'PERSONNEL_DUK' && (
                $issue->code === 'NON_NIP_TEXT_PPNPN'
                || str_contains($issue->code, 'SUPPLEMENTAL')
                || ($issue->source_sheet && $issue->source_sheet !== 'DUK PEGAWAI')
            )));
    }

    public function getReviewWarningRowsAttribute(): int
    {
        return $this->review_issues->where('severity', 'WARNING')->unique(fn ($issue) => $issue->source_sheet.':'.$issue->source_row)->count();
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'validated' => 'Siap disimpan',
            'committed' => 'Sudah disimpan',
            'failed' => 'Perlu diperbaiki',
            default => 'Sedang diperiksa',
        };
    }

    public function getSourceLabelAttribute(): string
    {
        return $this->source_type === 'PERSONNEL_DUK' ? 'DUK Pegawai' : 'Peta Jabatan / Kebutuhan';
    }

    protected function casts(): array
    {
        return ['summary' => 'array', 'committed_at' => 'datetime'];
    }

    public function period()
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function issues()
    {
        return $this->hasMany(ImportIssue::class);
    }
}
