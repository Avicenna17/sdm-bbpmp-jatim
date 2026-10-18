<?php

namespace App\Policies\Pak;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class FoundationPolicy
{
    protected string $viewPermission;

    protected ?string $managePermission = null;

    public function viewAny(User $user): bool
    {
        return Access::allows($user, $this->viewPermission);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->managePermission && Access::allows($user, $this->managePermission);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }
}
