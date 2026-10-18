<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Models\PakRuleSet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PakRuleSetService
{
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
