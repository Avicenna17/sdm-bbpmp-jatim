<?php

namespace App\Models;

use App\Domain\Pak\Rules\ConversionRuleValues;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AkCoefficientRule extends PakModel
{
    protected function casts(): array
    {
        return ['annual_coefficient' => 'decimal:6'];
    }

    public function ruleSet()
    {
        return $this->belongsTo(PakRuleSet::class, 'pak_rule_set_id');
    }

    public function functionalLevel()
    {
        return $this->belongsTo(FunctionalLevel::class);
    }

    public function setAnnualCoefficientAttribute(mixed $value): void
    {
        $this->attributes['annual_coefficient'] = ConversionRuleValues::annualCoefficient($value);
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (self $row) {
            if ($row->exists && (string) $row->pak_rule_set_id !== (string) $row->getRawOriginal('pak_rule_set_id')) {
                throw ValidationException::withMessages(['pak_rule_set_id' => 'Child tidak boleh dipindahkan ke versi lain.']);
            }
            $query = $row->ruleSet()->useWritePdo();
            if ($row->getConnection()->transactionLevel() > 0) {
                $query->sharedLock();
            }
            $parent = $query->first();
            if (! $parent || $parent->status !== 'DRAFT' || $parent->first_used_at !== null) {
                throw ValidationException::withMessages(['pak_rule_set_id' => 'Child hanya dapat ditulis pada DRAFT yang belum digunakan.']);
            }
            Validator::make($row->getAttributes(), ['functional_level_id' => ['required', 'integer', 'min:1']])->validate();
            if (! $row->functionalLevel()->exists()) {
                throw ValidationException::withMessages(['functional_level_id' => 'Jenjang wajib merupakan master yang tersimpan.']);
            }
            $value = $row->getAttributes()['annual_coefficient'] ?? null;
            if (! $row->exists || $value !== $row->getRawOriginal('annual_coefficient')) {
                $row->annual_coefficient = ConversionRuleValues::annualCoefficient($value);
            }
        });
    }
}
