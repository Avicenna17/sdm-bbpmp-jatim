<?php

namespace App\Domain\Pak\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class PakEmployeeAuthorization
{
    public static function allows(?User $actor, string $permission): bool
    {
        return $actor && (bool) $actor->fresh()?->is_active && Gate::forUser($actor)->allows('pak.access') && Gate::forUser($actor)->allows($permission);
    }

    public static function authorize(User $actor, string $permission): void
    {
        abort_unless(self::allows($actor, $permission), 403);
    }
}
