<?php

namespace App\Models;

use App\Domain\Pak\Rules\ConversionPersistenceValues as Values;
use App\Domain\Pak\Rules\ConversionRuleValues;
use Illuminate\Support\Facades\Validator;

class PakConversionPeriod extends PakModel
{
    public function setAttribute($key, $value)
    {
        if (in_array($key, ['period_start', 'period_end'], true)) {
            if (! is_string($value)) {
                Values::reject($key);
            }
            Values::date($value, $key);
            $this->attributes[$key] = $value;

            return $this;
        }
        if (in_array($key, ['source_snapshot', 'position_snapshot', 'rule_snapshot', 'original_issues', 'calculation_trace'], true)) {
            $this->attributes[$key] = Values::json($value, $key, str_ends_with($key, '_snapshot'));

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'predicate_factor' => 'decimal:4', 'annual_coefficient' => 'decimal:6', 'source_snapshot' => 'array', 'position_snapshot' => 'array', 'rule_snapshot' => 'array', 'original_issues' => 'array', 'calculation_trace' => 'array'];
    }

    public function setPredicateFactorAttribute(mixed $value): void
    {
        $this->attributes['predicate_factor'] = $value === null ? null : ConversionRuleValues::predicateFactor($value);
    }

    public function setAnnualCoefficientAttribute(mixed $value): void
    {
        $this->attributes['annual_coefficient'] = $value === null ? null : ConversionRuleValues::annualCoefficient($value);
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (self $line) {
            if ($line->exists && $line->isDirty()) {
                Values::reject('snapshot');
            }
            $a = $line->getAttributes();
            Validator::make($a, ['line_number' => ['required', 'integer', 'min:1'], 'month_count' => ['required', 'integer', 'min:1']])->validate();
            $start = Values::date($a['period_start'] ?? '', 'period_start');
            $end = Values::date($a['period_end'] ?? '', 'period_end');
            if ($start > $end || $start->format('d') !== '01' || $end->format('d') !== $end->format('t')) {
                Values::reject('period_start');
            }
            $count = ((int) $end->format('Y') - (int) $start->format('Y')) * 12 + (int) $end->format('m') - (int) $start->format('m') + 1;
            if ($count !== (int) $line->month_count) {
                Values::reject('month_count');
            }
            $run = PakConversionRun::find($line->pak_conversion_run_id);
            if (! $run || $run->requested_start->day !== 1 || ! $run->requested_end->isLastOfMonth()
                || $start->format('Y-m-d') < $run->requested_start->format('Y-m-d') || $end->format('Y-m-d') > $run->requested_end->format('Y-m-d')) {
                Values::reject('pak_conversion_run_id');
            }
            Values::rational($a['result_numerator'] ?? null, $a['result_denominator'] ?? null, 'result');
            if (self::where('pak_conversion_run_id', $run->id)->when($line->exists, fn ($q) => $q->whereKeyNot($line->id))->where('period_start', '<=', $end->format('Y-m-d'))->where('period_end', '>=', $start->format('Y-m-d'))->exists()) {
                Values::reject('period_start');
            }
            $dependencies = [];
            foreach (['performance_evaluation_id' => PerformanceEvaluation::class, 'source_position_history_id' => EmployeePositionHistory::class, 'employee_position_history_id' => EmployeePositionHistory::class] as $field => $model) {
                if ($line->$field !== null) {
                    $dependency = $model::find($line->$field);
                    if (! $dependency || (string) $dependency->person_id !== (string) $run->person_id) {
                        Values::reject($field);
                    }
                    $dependencies[$field] = $dependency;
                }
            }
            foreach (['performance_predicate_rule_id' => PerformancePredicateRule::class, 'ak_coefficient_rule_id' => AkCoefficientRule::class] as $field => $model) {
                if ($line->$field !== null) {
                    $dependency = $model::find($line->$field);
                    if (! $dependency || (string) $dependency->pak_rule_set_id !== (string) $run->pak_rule_set_id) {
                        Values::reject($field);
                    }
                    if ($field === 'ak_coefficient_rule_id' && (string) $dependency->functional_level_id !== (string) $line->functional_level_id) {
                        Values::reject('functional_level_id');
                    }
                    $dependencies[$field] = $dependency;
                }
            }
            $evaluation = $dependencies['performance_evaluation_id'] ?? null;
            $predicate = $dependencies['performance_predicate_rule_id'] ?? null;
            $position = $dependencies['employee_position_history_id'] ?? null;
            if ($evaluation !== null) {
                // Applied months share the performance year, not necessarily formal source coverage.
                if ($start->format('Y') !== (string) $evaluation->performance_year || $end->format('Y') !== (string) $evaluation->performance_year) {
                    Values::reject('performance_evaluation_id');
                }
                if ($predicate !== null && $evaluation->predicate_code !== $predicate->predicate_code) {
                    Values::reject('performance_predicate_rule_id');
                }
                if ($evaluation->employee_position_history_id !== null && $line->source_position_history_id !== null
                    && (string) $evaluation->employee_position_history_id !== (string) $line->source_position_history_id) {
                    Values::reject('source_position_history_id');
                }
            }
            if ($position !== null && $line->functional_level_id !== null && (string) $position->functional_level_id !== (string) $line->functional_level_id) {
                Values::reject('functional_level_id');
            }
            foreach (['original_issues', 'calculation_trace'] as $field) {
                Values::json($line->$field, $field);
            }
            foreach (['source_snapshot', 'position_snapshot', 'rule_snapshot'] as $field) {
                Values::json($line->$field, $field, true);
            }
            if ($line->result_numerator !== null) {
                foreach (['performance_evaluation_id', 'employee_position_history_id', 'functional_level_id', 'performance_predicate_rule_id', 'ak_coefficient_rule_id', 'predicate_factor', 'annual_coefficient', 'source_snapshot', 'position_snapshot', 'rule_snapshot'] as $field) {
                    if ($line->$field === null) {
                        Values::reject($field);
                    }
                }
                if (ConversionRuleValues::predicateFactor($line->predicate_factor) !== ConversionRuleValues::predicateFactor($predicate->predicate_factor)) {
                    Values::reject('predicate_factor');
                }
                if (ConversionRuleValues::annualCoefficient($line->annual_coefficient) !== ConversionRuleValues::annualCoefficient($dependencies['ak_coefficient_rule_id']->annual_coefficient)) {
                    Values::reject('annual_coefficient');
                }
            } elseif ($line->original_issues === []) {
                Values::reject('original_issues');
            }
        });
    }

    public function run()
    {
        return $this->belongsTo(PakConversionRun::class, 'pak_conversion_run_id');
    }

    public function evaluation()
    {
        return $this->belongsTo(PerformanceEvaluation::class, 'performance_evaluation_id');
    }

    public function sourcePositionHistory()
    {
        return $this->belongsTo(EmployeePositionHistory::class, 'source_position_history_id');
    }

    public function positionHistory()
    {
        return $this->belongsTo(EmployeePositionHistory::class, 'employee_position_history_id');
    }

    public function functionalLevel()
    {
        return $this->belongsTo(FunctionalLevel::class, 'functional_level_id');
    }

    public function predicateRule()
    {
        return $this->belongsTo(PerformancePredicateRule::class, 'performance_predicate_rule_id');
    }

    public function coefficientRule()
    {
        return $this->belongsTo(AkCoefficientRule::class, 'ak_coefficient_rule_id');
    }
}
