<?php

namespace App\Domain\Users;

use App\Domain\Pak\Services\PakAuditService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountStatusService
{
    public function change(User $actor, User $target, bool $active, string $reason): User
    {
        abort_unless($actor->fresh()?->is_active, 403);
        Gate::forUser($actor)->authorize('user.manage');
        Validator::make(['reason' => $reason], ['reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $target, $active, $reason) {
            $users = User::orderBy('id')->lockForUpdate()->get();
            $target = $users->find($target->id);
            abort_unless($target, 404);
            if (! $active) {
                if ($target->is($actor)) {
                    throw ValidationException::withMessages(['user' => 'Tidak dapat menonaktifkan akun sendiri.']);
                }
                $administrators = $users->filter(fn ($u) => $u->is_active && $u->can('period.view') && $u->can('user.manage') && $u->can('role.manage'));
                if ($administrators->contains('id', $target->id) && $administrators->count() === 1) {
                    throw ValidationException::withMessages(['user' => 'Administrator aktif terakhir harus dipertahankan.']);
                }
            }
            $before = $target->is_active;
            $target->forceFill(['is_active' => $active, 'remember_token' => Str::random(60)])->save();
            app(PakAuditService::class)->record($actor, $target, $active ? 'ACCOUNT_REACTIVATED' : 'ACCOUNT_DEACTIVATED', ['before' => ['is_active' => $before], 'after' => ['is_active' => $active], 'reason' => $reason]);

            return $target;
        });
    }
}
