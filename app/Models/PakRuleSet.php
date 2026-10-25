<?php

namespace App\Models;

use Illuminate\Validation\ValidationException;

class PakRuleSet extends PakModel
{
    protected $guarded = ['id', 'first_used_at'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date', 'first_used_at' => 'datetime'];
    }

    public function cases()
    {
        return $this->hasMany(PakCase::class);
    }

    public function performancePredicateRules()
    {
        return $this->hasMany(PerformancePredicateRule::class);
    }

    public function akCoefficientRules()
    {
        return $this->hasMany(AkCoefficientRule::class);
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function ($row) {
            if ($row->getOriginal('first_used_at') !== null || $row->newQuery()->whereKey($row->getKey())->whereNotNull('first_used_at')->exists()) {
                throw ValidationException::withMessages(['rule' => 'Versi sudah digunakan; buat versi baru.']);
            }
        });
    }
}
