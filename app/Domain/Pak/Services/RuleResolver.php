<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\DTOs\ResolvedCoefficientRule;
use App\Domain\Pak\DTOs\ResolvedPredicateRule;
use App\Domain\Pak\Exceptions\ConversionRuleException;
use App\Domain\Pak\Rules\ConversionRuleValues;
use App\Models\AkCoefficientRule;
use App\Models\PakRuleSet;
use App\Models\PerformancePredicateRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class RuleResolver
{
    // Runtime checks also reject scalar coercion from non-strict PHP callers.
    public function resolvePredicate(mixed $ruleSetId, mixed $predicateCode): ResolvedPredicateRule
    {
        $this->inputId($ruleSetId);
        try {
            if (ConversionRuleValues::predicateCode($predicateCode) !== $predicateCode) {
                throw new ConversionRuleException('INVALID_RULE_INPUT');
            }
        } catch (ValidationException) {
            throw new ConversionRuleException('INVALID_RULE_INPUT');
        }
        $this->ruleSet($ruleSetId);
        $rows = PerformancePredicateRule::where('pak_rule_set_id', $ruleSetId)->where('predicate_code', $predicateCode)->limit(2)->get();
        if ($rows->isEmpty()) {
            throw new ConversionRuleException('PREDICATE_RULE_NOT_FOUND');
        }
        if ($rows->count() !== 1) {
            throw new ConversionRuleException('RULE_INTEGRITY_ERROR');
        }
        $row = $rows->sole();
        $code = $row->getRawOriginal('predicate_code');
        $name = $row->getRawOriginal('predicate_name');
        try {
            if ((int) $row->pak_rule_set_id !== $ruleSetId || $code !== $predicateCode
                || ConversionRuleValues::predicateCode($code) !== $code
                || ! is_string($name) || ! mb_check_encoding($name, 'UTF-8')
                || preg_match('/\A[\p{Z}\s]*\z/u', $name) || mb_strlen($name, 'UTF-8') > 100) {
                throw new ConversionRuleException('RULE_INTEGRITY_ERROR');
            }
            $factor = ConversionRuleValues::predicateFactor($this->storedDecimal($row, 'predicate_factor'));
        } catch (ValidationException) {
            throw new ConversionRuleException('RULE_INTEGRITY_ERROR');
        }

        return new ResolvedPredicateRule($ruleSetId, (int) $row->id, $code, $name, $factor);
    }

    public function resolveCoefficient(mixed $ruleSetId, mixed $functionalLevelId): ResolvedCoefficientRule
    {
        $this->inputId($ruleSetId);
        $this->inputId($functionalLevelId);
        $this->ruleSet($ruleSetId);
        $rows = AkCoefficientRule::where('pak_rule_set_id', $ruleSetId)->where('functional_level_id', $functionalLevelId)->limit(2)->get();
        if ($rows->isEmpty()) {
            throw new ConversionRuleException('COEFFICIENT_RULE_NOT_FOUND');
        }
        if ($rows->count() !== 1) {
            throw new ConversionRuleException('RULE_INTEGRITY_ERROR');
        }
        $row = $rows->sole();
        if ((int) $row->pak_rule_set_id !== $ruleSetId || (int) $row->functional_level_id !== $functionalLevelId) {
            throw new ConversionRuleException('RULE_INTEGRITY_ERROR');
        }
        try {
            $coefficient = ConversionRuleValues::annualCoefficient($this->storedDecimal($row, 'annual_coefficient'));
        } catch (ValidationException) {
            throw new ConversionRuleException('RULE_INTEGRITY_ERROR');
        }

        return new ResolvedCoefficientRule($ruleSetId, (int) $row->id, $functionalLevelId, $coefficient);
    }

    private function inputId(mixed $id): void
    {
        if (! is_int($id) || $id < 1) {
            throw new ConversionRuleException('INVALID_RULE_INPUT');
        }
    }

    private function ruleSet(int $id): void
    {
        $rule = PakRuleSet::find($id);
        if (! $rule) {
            throw new ConversionRuleException('RULE_SET_NOT_FOUND');
        }
        if ($rule->status !== 'DRAFT') {
            throw new ConversionRuleException('RULE_SET_NOT_USABLE');
        }
    }

    private function storedDecimal(Model $row, string $field): mixed
    {
        $value = $row->getRawOriginal($field);
        // MySQL/MariaDB return DECIMAL strings. SQLite hydrates numeric scalars;
        // serialize its stored value without decimal casts, rounding, or arithmetic.
        if ($row->getConnection()->getDriverName() !== 'sqlite' || (! is_int($value) && ! is_float($value))) {
            return $value;
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new ConversionRuleException('RULE_INTEGRITY_ERROR');
        }
        $text = json_encode($value, JSON_THROW_ON_ERROR);
        if (! preg_match('/\A([0-9]+)(?:\.([0-9]+))?[eE]([+-]?[0-9]+)\z/', $text, $parts)) {
            return $text;
        }
        // Expand only a numeric driver's exponent representation; raw text is
        // still validated unchanged. Moving the decimal point does not round.
        $digits = $parts[1].($parts[2] ?? '');
        $point = strlen($parts[1]) + (int) $parts[3];
        if ($point <= 0) {
            return '0.'.str_repeat('0', -$point).rtrim($digits, '0');
        }
        if ($point >= strlen($digits)) {
            return $digits.str_repeat('0', $point - strlen($digits));
        }

        return substr($digits, 0, $point).'.'.substr($digits, $point);
    }
}
