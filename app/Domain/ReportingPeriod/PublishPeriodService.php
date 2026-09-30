<?php

namespace App\Domain\ReportingPeriod;

use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PublishPeriodService
{
    public function publish(User $user, ReportingPeriod $period): void
    {
        Gate::forUser($user)->authorize('period.publish');
        DB::transaction(function () use ($user, $period) {
            $period = ReportingPeriod::lockForUpdate()->findOrFail($period->id);
            if ($period->status === 'published') {
                Gate::forUser($user)->authorize('period.revise');
            }
            if (! $period->personnel()->exists() || ! $period->positions()->exists()) {
                throw ValidationException::withMessages(['period' => 'DUK dan Peta Jabatan harus di-commit sebelum publikasi.']);
            }
            $period->update(['status' => 'published', 'published_at' => now(), 'published_by' => $user->id, 'revision' => $period->revision + 1]);
        });
    }
}
