<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportingPeriod extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_month' => 'date', 'published_at' => 'datetime'];
    }

    public function personnel()
    {
        return $this->hasMany(PersonnelSnapshot::class);
    }

    public function positions()
    {
        return $this->hasMany(PositionRequirementSnapshot::class);
    }

    public function batches()
    {
        return $this->hasMany(ImportBatch::class);
    }

    public static function activePublished(): ?self
    {
        return static::where('status', 'published')->orderByDesc('period_month')->first();
    }

    public function publicationLabel(?self $active): string
    {
        if ($active && $this->period_month->lt($active->period_month)) {
            return 'Arsip';
        }

        return match ($this->status) {
            'published' => 'Aktif di dashboard',
            'ready' => 'Siap dipublikasikan',
            default => 'Belum ada DUK',
        };
    }

    public function canPublish(?self $active): bool
    {
        return $this->status !== 'published'
            && (! $active || $this->period_month->gt($active->period_month))
            && $this->personnel()->exists();
    }

    public function getLabelAttribute(): string
    {
        return $this->period_month->translatedFormat('F Y');
    }
}
