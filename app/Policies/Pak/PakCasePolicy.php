<?php

namespace App\Policies\Pak;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PakCasePolicy extends FoundationPolicy
{
    protected string $viewPermission = 'pak.case.view';

    public function create(User $user): bool
    {
        return Access::allows($user, 'pak.case.create') && Access::allows($user, 'pak.employee.view');
    }

    public function update(User $user, Model $record): bool
    {
        return Access::allows($user, 'pak.case.update') && Access::allows($user, 'pak.employee.view') && $record->status === 'DRAFT' && ! $record->finalized_at;
    }
}
