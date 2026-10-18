<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FunctionalMasterService
{
    public const TYPES = ['category' => FunctionalCategory::class, 'level' => FunctionalLevel::class, 'position' => FunctionalPosition::class, 'mapping' => FunctionalPositionLevel::class];

    public function save(User $actor, string $type, array $input, ?int $id = null)
    {
        Access::authorize($actor, 'pak.master.manage');
        abort_unless(isset(self::TYPES[$type]), 422);
        $class = self::TYPES[$type];

        return DB::transaction(function () use ($actor, $type, $class, $input, $id) {
            $record = $id ? $class::lockForUpdate()->findOrFail($id) : new $class;
            $rules = ['is_active' => 'required|boolean'];
            if ($type === 'mapping') {
                $rules += ['functional_position_id' => ['required', 'integer', Rule::exists('functional_positions', 'id')], 'functional_level_id' => ['required', 'integer', Rule::exists('functional_levels', 'id'), Rule::unique('functional_position_levels')->where('functional_position_id', $input['functional_position_id'] ?? 0)->ignore($id)]];
            } else {
                $rules += ['name' => 'required|string|max:255', 'code' => [$type === 'position' ? 'nullable' : 'required', 'string', 'max:100', Rule::unique($record->getTable(), 'code')->ignore($id)]];
                if ($type !== 'position') {
                    $rules['sort_order'] = 'required|integer|min:0|max:65535';
                }
                if ($type === 'level') {
                    $rules['functional_category_id'] = 'required|exists:functional_categories,id';
                }
            }
            $data = Validator::make($input, $rules)->validate();
            // Stable identity: change labels/active state, never repurpose an existing code or mapping.
            foreach (['code', 'functional_category_id', 'functional_position_id', 'functional_level_id'] as $key) {
                if ($id && array_key_exists($key, $data) && (string) $data[$key] !== (string) $record->$key) {
                    throw ValidationException::withMessages([$key => 'Identitas master tetap; buat master/mapping baru.']);
                }
            }
            if ($type === 'mapping' && $data['is_active']) {
                $position = FunctionalPosition::lockForUpdate()->findOrFail($data['functional_position_id']);
                $level = FunctionalLevel::lockForUpdate()->findOrFail($data['functional_level_id']);
                $category = FunctionalCategory::lockForUpdate()->findOrFail($level->functional_category_id);
                if (! $position->is_active || ! $level->is_active || ! $category->is_active) {
                    throw ValidationException::withMessages(['is_active' => 'Mapping aktif membutuhkan JF, jenjang, dan kategori yang aktif.']);
                }
            }
            $before = $record->getAttributes();
            $record->fill($data)->save();
            app(PakAuditService::class)->record($actor, $record, 'MASTER_SAVED', ['before' => $before, 'after' => $record->getAttributes()]);

            return $record;
        });
    }
}
