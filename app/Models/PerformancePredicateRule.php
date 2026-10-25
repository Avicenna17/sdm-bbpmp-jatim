<?php

namespace App\Models;

use App\Domain\Pak\Rules\ConversionRuleValues;
use Illuminate\Validation\ValidationException;

class PerformancePredicateRule extends PakModel
{
    protected function casts(): array
    {
        return ['predicate_factor' => 'decimal:4'];
    }

    public function ruleSet()
    {
        return $this->belongsTo(PakRuleSet::class, 'pak_rule_set_id');
    }

    public function setPredicateFactorAttribute(mixed $value): void
    {
        // Validate before decimal casts can round/coerce an authoritative input.
        $this->attributes['predicate_factor'] = ConversionRuleValues::predicateFactor($value);
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (self $row) {
            if ($row->exists && (string) $row->pak_rule_set_id !== (string) $row->getRawOriginal('pak_rule_set_id')) {
                throw ValidationException::withMessages(['pak_rule_set_id' => 'Child tidak boleh dipindahkan ke versi lain.']);
            }
            // Reload persisted state, including when a caller has cached an unused parent.
            $query = $row->ruleSet()->useWritePdo();
            if ($row->getConnection()->transactionLevel() > 0) {
                $query->sharedLock();
            }
            $parent = $query->first();
            if (! $parent || $parent->status !== 'DRAFT' || $parent->first_used_at !== null) {
                throw ValidationException::withMessages(['pak_rule_set_id' => 'Child hanya dapat ditulis pada DRAFT yang belum digunakan.']);
            }
            $row->predicate_code = ConversionRuleValues::predicateCode($row->getAttributes()['predicate_code'] ?? null);
            $name = $row->getAttributes()['predicate_name'] ?? null;
            if (! is_string($name) || ! mb_check_encoding($name, 'UTF-8') || preg_match('/\A[\p{Z}\s]*\z/u', $name) || mb_strlen($name, 'UTF-8') > 100) {
                throw ValidationException::withMessages(['predicate_name' => 'Label wajib nonblank UTF-8, maksimum 100 karakter.']);
            }
            $value = $row->getAttributes()['predicate_factor'] ?? null;
            // SQLite may hydrate DECIMAL as a numeric scalar; unchanged persisted values are not new inputs.
            if (! $row->exists || $value !== $row->getRawOriginal('predicate_factor')) {
                $row->predicate_factor = ConversionRuleValues::predicateFactor($value);
            }
        });
    }
}
