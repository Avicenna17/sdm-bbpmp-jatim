<?php

namespace App\Domain\Pak\Queries;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Models\PakCase;
use App\Models\User;

final class PakCaseQuery
{
    public function forManager(User $actor)
    {
        Access::authorize($actor, 'pak.case.view');

        return PakCase::query();
    }

    // Ownership boundary only: no portal endpoint or lifecycle visibility policy yet.
    public function forEmployee(User $actor)
    {
        $actor = $actor->fresh();

        return PakCase::where('person_id', $actor?->is_active && $actor->person_id ? $actor->person_id : 0);
    }
}
