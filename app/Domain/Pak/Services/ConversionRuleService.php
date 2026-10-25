<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Models\AkCoefficientRule;
use App\Models\PakRuleSet;
use App\Models\PerformancePredicateRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConversionRuleService
{
    public function savePredicate(User $actor, int $ruleSetId, array $input, ?int $childId = null): PerformancePredicateRule
    {
        return $this->save($actor, $ruleSetId, $input, $childId, PerformancePredicateRule::class,
            ['predicate_code', 'predicate_name', 'predicate_factor'], 'PREDICATE_RULE');
    }

    public function saveCoefficient(User $actor, int $ruleSetId, array $input, ?int $childId = null): AkCoefficientRule
    {
        return $this->save($actor, $ruleSetId, $input, $childId, AkCoefficientRule::class,
            ['functional_level_id', 'annual_coefficient'], 'COEFFICIENT_RULE');
    }

    private function save(User $actor, int $ruleSetId, array $input, ?int $childId, string $model, array $fields, string $event): Model
    {
        Access::authorize($actor, 'pak.rule-set.manage');
        if (array_diff(array_keys($input), $fields) || array_diff($fields, array_keys($input))) {
            throw ValidationException::withMessages(['input' => 'INVALID_RULE_INPUT']);
        }

        try {
            return DB::transaction(function () use ($actor, $ruleSetId, $input, $childId, $model, $fields, $event) {
                $parent = PakRuleSet::lockForUpdate()->find($ruleSetId);
                if (! $parent) {
                    throw ValidationException::withMessages(['rule' => 'RULE_SET_NOT_FOUND']);
                }
                if ($parent->status !== 'DRAFT' || $parent->first_used_at !== null) {
                    throw ValidationException::withMessages(['rule' => 'RULE_SET_NOT_EDITABLE']);
                }
                $child = $childId === null ? new $model : $model::lockForUpdate()->find($childId);
                if (! $child) {
                    throw ValidationException::withMessages(['child' => 'RULE_CHILD_NOT_FOUND']);
                }
                if ($child->exists && (int) $child->pak_rule_set_id !== $ruleSetId) {
                    throw ValidationException::withMessages(['child' => 'RULE_CHILD_PARENT_MISMATCH']);
                }
                $auditFields = ['pak_rule_set_id', ...$fields];
                $before = $child->exists ? $child->only($auditFields) : null;
                $child->fill($input);
                if (! $child->exists) {
                    $child->pak_rule_set_id = $parent->id;
                }
                try {
                    $child->save();
                } catch (UniqueConstraintViolationException $error) {
                    throw ValidationException::withMessages(['child' => 'RULE_CHILD_CONFLICT']);
                }
                $parent->updated_by = $actor->id;
                $parent->save();
                app(PakAuditService::class)->record($actor, $child, $event.($childId === null ? '_CREATED' : '_UPDATED'),
                    ['before' => $before, 'after' => $child->only($auditFields)]);

                return $child;
            });
        } catch (QueryException $error) {
            throw ValidationException::withMessages(['persistence' => 'PERSISTENCE_FAILED']);
        }
    }
}
