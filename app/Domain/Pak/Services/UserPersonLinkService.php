<?php

namespace App\Domain\Pak\Services;

use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UserPersonLinkService
{
    public function link(User $actor, User $target, ?int $personId, string $reason): User
    {
        abort_unless($actor->fresh()?->is_active, 403);
        Gate::forUser($actor)->authorize('user.manage');
        Validator::make(['reason' => $reason], ['reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $target, $personId, $reason) {
            // Lock accounts consistently with account status changes.
            User::orderBy('id')->lockForUpdate()->get(['id']);
            $target = User::findOrFail($target->id);
            if ($personId !== null) {
                Person::lockForUpdate()->findOrFail($personId);
                if (User::where('person_id', $personId)->whereKeyNot($target->id)->exists()) {
                    throw ValidationException::withMessages(['person_id' => 'Person sudah terhubung ke akun lain.']);
                }
            }
            $before = $target->person_id;
            $target->forceFill(['person_id' => $personId])->save();
            app(PakAuditService::class)->record($actor, $target, 'USER_PERSON_LINKED', ['before' => ['person_id' => $before], 'after' => ['person_id' => $personId], 'reason' => $reason]);

            return $target;
        });
    }
}
