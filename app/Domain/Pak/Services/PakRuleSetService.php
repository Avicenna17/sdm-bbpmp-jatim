<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Models\PakRuleSet;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PakRuleSetService
{
    public function copyVersion(User $actor, int $sourceRuleSetId, string $newVersion): PakRuleSet
    {
        Access::authorize($actor, 'pak.rule-set.manage');
        Access::authorize($actor, 'pak.rule-set.view');
        Validator::make(['version' => $newVersion], ['version' => 'required|string|max:50'])->validate();

        try {
            return DB::transaction(function () use ($actor, $sourceRuleSetId, $newVersion) {
                $source = PakRuleSet::lockForUpdate()->find($sourceRuleSetId);
                if (! $source) {
                    throw ValidationException::withMessages(['rule' => 'RULE_SET_NOT_FOUND']);
                }
                if ($source->status !== 'DRAFT') {
                    throw ValidationException::withMessages(['rule' => 'RULE_SET_NOT_EDITABLE']);
                }
                $predicates = $source->performancePredicateRules()->orderBy('id')->sharedLock()->get();
                $coefficients = $source->akCoefficientRules()->orderBy('id')->sharedLock()->get();
                try {
                    $target = PakRuleSet::create($source->only(['code', 'name', 'valid_from', 'valid_until', 'reference']) + [
                        'version' => $newVersion, 'status' => 'DRAFT', 'created_by' => $actor->id, 'updated_by' => $actor->id,
                    ]);
                } catch (UniqueConstraintViolationException $error) {
                    throw ValidationException::withMessages(['version' => 'RULE_VERSION_CONFLICT']);
                }
                foreach ($predicates as $child) {
                    $target->performancePredicateRules()->create($child->only(['predicate_code', 'predicate_name', 'predicate_factor']));
                }
                foreach ($coefficients as $child) {
                    $target->akCoefficientRules()->create($child->only(['functional_level_id', 'annual_coefficient']));
                }
                app(PakAuditService::class)->record($actor, $target, 'RULE_SET_COPIED', ['after' => [
                    'source_rule_set_id' => $source->id, 'target_rule_set_id' => $target->id,
                    'code' => $target->code, 'version' => $target->version, 'status' => $target->status,
                    'predicate_count' => $predicates->count(), 'coefficient_count' => $coefficients->count(),
                ]]);

                return $target;
            });
        } catch (QueryException $error) {
            throw ValidationException::withMessages(['persistence' => 'PERSISTENCE_FAILED']);
        }
    }

    public function save(User $actor, array $input, ?int $id = null): PakRuleSet
    {
        Access::authorize($actor, 'pak.rule-set.manage');
        $data = Validator::make($input, [
            'code' => 'required|string|max:100', 'name' => 'required|string|max:255',
            'version' => ['required', 'string', 'max:50', Rule::unique('pak_rule_sets')->where('code', $input['code'] ?? '')->ignore($id)],
            'valid_from' => 'nullable|date_format:Y-m-d', 'valid_until' => ['nullable', 'date_format:Y-m-d', ...(! empty($input['valid_from']) ? ['after_or_equal:valid_from'] : [])],
            'reference' => 'nullable|string|max:10000', 'status' => 'sometimes|in:DRAFT',
        ])->validate();

        return DB::transaction(function () use ($actor, $data, $id) {
            $rule = $id ? PakRuleSet::lockForUpdate()->findOrFail($id) : new PakRuleSet;
            if ($id && ($rule->status !== 'DRAFT' || $rule->first_used_at !== null)) {
                throw ValidationException::withMessages(['rule' => 'Versi terkunci; salin sebagai versi baru.']);
            }
            $before = $rule->getAttributes();
            $rule->fill($data + ['status' => 'DRAFT', 'updated_by' => $actor->id]);
            if (! $id) {
                $rule->created_by = $actor->id;
            }
            $rule->save();
            app(PakAuditService::class)->record($actor, $rule, 'RULE_METADATA_SAVED', ['before' => $before, 'after' => $rule->getAttributes()]);

            return $rule;
        });
    }
}
