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

    public function getLabelAttribute(): string
    {
        return $this->period_month->translatedFormat('F Y');
    }
}
