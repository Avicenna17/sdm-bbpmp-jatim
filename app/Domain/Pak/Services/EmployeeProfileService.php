<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Models\EmployeeProfile;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class EmployeeProfileService
{
    public function save(User $actor, Person $person, array $input): EmployeeProfile
    {
        Access::authorize($actor, 'pak.employee.manage');
        $data = Validator::make($input, ['karpeg_number' => 'nullable|string|max:100', 'birth_place' => 'nullable|string|max:150', 'birth_date' => 'nullable|date', 'gender' => 'nullable|in:L,P', 'institution_name' => 'nullable|string|max:255', 'work_unit_name' => 'nullable|string|max:255', 'source_reference' => 'required|string|max:255'])->validate();

        return DB::transaction(function () use ($actor, $person, $data) {
            Person::lockForUpdate()->findOrFail($person->id);
            $profile = EmployeeProfile::firstOrNew(['person_id' => $person->id]);
            $before = $profile->getAttributes();
            $profile->fill($data + ['verified_by' => $actor->id, 'verified_at' => now(), 'updated_by' => $actor->id]);
            if (! $profile->exists) {
                $profile->created_by = $actor->id;
            }
            $profile->save();
            app(PakAuditService::class)->record($actor, $profile, 'PROFILE_VERIFIED', ['before' => $before, 'after' => $profile->getAttributes()]);

            return $profile;
        });
    }
}
