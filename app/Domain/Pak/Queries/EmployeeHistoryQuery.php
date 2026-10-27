<?php

namespace App\Domain\Pak\Queries;

use App\Models\EmployeePositionHistory;
use App\Models\EmployeeRankHistory;

final class EmployeeHistoryQuery
{
    public function at(int $personId, string $date, bool $position = false, bool $current = false)
    {
        $class = $position ? EmployeePositionHistory::class : EmployeeRankHistory::class;

        return $class::where('person_id', $personId)->whereNull('superseded_at')->whereNotNull('verified_at')
            ->whereDate('tmt_start', '<=', $date)->where(fn ($q) => $q->whereNull('tmt_end')->orWhereDate('tmt_end', '>=', $date))
            ->when($current, fn ($q) => $q->sharedLock())->first();
    }
}
