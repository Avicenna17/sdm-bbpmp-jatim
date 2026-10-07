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
            // Serialize publication so an older month cannot replace a newer publication.
            $periods = ReportingPeriod::orderBy('id')->lockForUpdate()->get();
            $period = $periods->firstWhere('id', $period->id);
            abort_unless($period, 404);
            $active = $periods->where('status', 'published')->sortByDesc('period_month')->first();
            if ($active && $period->period_month->lt($active->period_month)) {
                throw ValidationException::withMessages(['period' => 'Periode arsip tidak dapat dipublikasikan kembali.']);
            }
            if ($period->status === 'published') {
                return;
            }
            if (! $period->personnel()->exists()) {
                throw ValidationException::withMessages(['period' => 'DUK harus disimpan sebelum publikasi periode.']);
            }
            $period->update(['status' => 'published', 'published_at' => now(), 'published_by' => $user->id, 'revision' => $period->revision + 1]);
        });
    }
}
