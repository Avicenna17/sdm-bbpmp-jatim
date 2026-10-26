<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\DTOs\MonthlyCoverage;
use App\Domain\Pak\DTOs\MonthlyCoverageRequest;
use App\Domain\Pak\DTOs\SkpRequestedYearPartition;

final class MonthlyCoverageResolver
{
    public function resolve(MonthlyCoverageRequest $request): MonthlyCoverage
    {
        // OD-03: inspect the original requested boundaries; never normalize them.
        if (! $request->isFullMonth()) {
            return MonthlyCoverage::partialReview($request);
        }

        $firstYear = (int) substr($request->requested_start, 0, 4);
        $lastYear = (int) substr($request->requested_end, 0, 4);
        $partitions = [];
        for ($year = $firstYear; $year <= $lastYear; $year++) {
            $startMonth = $year === $firstYear ? (int) substr($request->requested_start, 5, 2) : 1;
            $endMonth = $year === $lastYear ? (int) substr($request->requested_end, 5, 2) : 12;
            $partition = new SkpRequestedYearPartition($year, $startMonth, $endMonth);
            $partitions[] = $partition;
        }

        return MonthlyCoverage::complete($request, $partitions);
    }
}
