<?php

namespace App\Domain\Pak\DTOs;

use InvalidArgumentException;

final readonly class MonthlyCoverage
{
    public bool $is_complete;

    public ?int $month_count;

    /**
     * @internal Resolver output; completeness concerns month boundaries, not conversion eligibility.
     *
     * @param  list<string>|null  $months  Null means unresolved, never a valid zero-month calculation.
     * @param  list<SkpRequestedYearPartition>|null  $year_partitions
     * @param  list<array{issue_code: string}>  $issues
     */
    private function __construct(
        public string $requested_start,
        public string $requested_end,
        public ?array $months,
        public ?array $year_partitions,
        public array $issues,
    ) {
        $this->is_complete = $months !== null;
        $this->month_count = $months === null ? null : count($months);
    }

    /** @param list<SkpRequestedYearPartition> $partitions */
    public static function complete(MonthlyCoverageRequest $request, array $partitions): self
    {
        if (! $request->isFullMonth() || $partitions === [] || ! array_is_list($partitions)) {
            throw new InvalidArgumentException('INVALID_MONTHLY_COVERAGE_RESULT');
        }
        $months = [];
        $previousYear = null;
        $expected = (int) substr($request->requested_start, 0, 4) * 12 + (int) substr($request->requested_start, 5, 2);
        $last = (int) substr($request->requested_end, 0, 4) * 12 + (int) substr($request->requested_end, 5, 2);
        foreach ($partitions as $partition) {
            if (! $partition instanceof SkpRequestedYearPartition || ($previousYear !== null && $partition->year <= $previousYear)) {
                throw new InvalidArgumentException('INVALID_MONTHLY_COVERAGE_RESULT');
            }
            foreach ($partition->months() as $month) {
                $ordinal = (int) substr($month, 0, 4) * 12 + (int) substr($month, 5, 2);
                // Exact succession rejects gaps, duplicates, reordered months and mismatched endpoints.
                if ($ordinal !== $expected || $ordinal > $last) {
                    throw new InvalidArgumentException('INVALID_MONTHLY_COVERAGE_RESULT');
                }
                $months[] = $month;
                $expected++;
            }
            $previousYear = $partition->year;
        }
        if ($months === [] || $expected !== $last + 1) {
            throw new InvalidArgumentException('INVALID_MONTHLY_COVERAGE_RESULT');
        }

        return new self($request->requested_start, $request->requested_end, $months, $partitions, []);
    }

    public static function partialReview(MonthlyCoverageRequest $request): self
    {
        if ($request->isFullMonth()) {
            throw new InvalidArgumentException('INVALID_MONTHLY_COVERAGE_RESULT');
        }

        return new self($request->requested_start, $request->requested_end, null, null,
            [['issue_code' => 'PARTIAL_MONTH_REQUIRES_REVIEW']]);
    }
}
