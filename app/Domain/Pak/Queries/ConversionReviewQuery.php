<?php

namespace App\Domain\Pak\Queries;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Models\PakConversionRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ConversionReviewQuery
{
    public function forManager(User $actor): Builder
    {
        Access::authorize($actor, 'pak.case.view');
        Access::authorize($actor, 'pak.conversion.view');

        return PakConversionRun::query()
            ->whereColumn('active_case_id', 'pak_case_id')
            ->where('status', 'REQUIRES_REVIEW')
            ->whereNull('superseded_at')
            ->whereNull('superseded_by_user_id')
            // Active identity is authoritative; a contradictory higher revision fails closed.
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('pak_conversion_runs as newer')
                ->whereColumn('newer.pak_case_id', 'pak_conversion_runs.pak_case_id')
                ->whereColumn('newer.revision_number', '>', 'pak_conversion_runs.revision_number'));
    }
}
