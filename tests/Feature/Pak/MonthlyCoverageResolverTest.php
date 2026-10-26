<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\MonthlyCoverage;
use App\Domain\Pak\DTOs\MonthlyCoverageRequest;
use App\Domain\Pak\DTOs\SkpRequestedYearPartition;
use App\Domain\Pak\Services\MonthlyCoverageResolver;
use Error;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonthlyCoverageResolverTest extends TestCase
{
    // Pure domain tests: no migrations, model fixtures, or database setup.
    public static function fullRanges(): array
    {
        return [
            ['2026-01-01', '2026-03-31', ['2026-01', '2026-02', '2026-03'], [[2026, 1, 3]]],
            ['2026-02-01', '2026-02-28', ['2026-02'], [[2026, 2, 2]]],
            ['2024-02-01', '2024-02-29', ['2024-02'], [[2024, 2, 2]]],
            ['2025-12-01', '2026-02-28', ['2025-12', '2026-01', '2026-02'], [[2025, 12, 12], [2026, 1, 2]]],
            ['2025-12-01', '2026-01-31', ['2025-12', '2026-01'], [[2025, 12, 12], [2026, 1, 1]]],
            ['2026-04-01', '2026-04-30', ['2026-04'], [[2026, 4, 4]]],
        ];
    }

    #[DataProvider('fullRanges')]
    public function test_full_month_ranges_and_exact_case_range(string $start, string $end, array $months, array $partitions): void
    {
        $input = MonthlyCoverageRequest::from($start, $end, $start, $end);
        $result = (new MonthlyCoverageResolver)->resolve($input);
        $this->assertTrue($result->is_complete);
        $this->assertSame($start, $result->requested_start);
        $this->assertSame($end, $result->requested_end);
        $this->assertSame($months, $result->months);
        $this->assertSame(count($months), $result->month_count);
        $this->assertSame([], $result->issues);
        $this->assertEquals(array_map(fn ($p) => new SkpRequestedYearPartition(...$p), $partitions), $result->year_partitions);
        $this->assertEquals($result, (new MonthlyCoverageResolver)->resolve($input));
    }

    public static function partialRanges(): array
    {
        return [
            ['2026-01-15', '2026-03-31'], ['2026-01-01', '2026-03-15'], ['2026-01-15', '2026-03-15'],
            ['2024-02-01', '2024-02-28'], ['2026-02-15', '2026-02-15'],
            ['2026-02-01', '2026-02-01'], ['2026-02-28', '2026-02-28'],
        ];
    }

    #[DataProvider('partialRanges')]
    public function test_partial_range_is_review_not_zero_or_interior_months(string $start, string $end): void
    {
        // Even an exact case range is not automatically full-month calculable.
        $input = MonthlyCoverageRequest::from($start, $end, $start, $end);
        $result = (new MonthlyCoverageResolver)->resolve($input);
        $this->assertFalse($result->is_complete);
        $this->assertSame($start, $result->requested_start);
        $this->assertSame($end, $result->requested_end);
        $this->assertNull($result->months);
        $this->assertNull($result->month_count);
        $this->assertNull($result->year_partitions);
        $this->assertSame([['issue_code' => 'PARTIAL_MONTH_REQUIRES_REVIEW']], $result->issues);
        $this->assertSame($start, $input->case_start);
        $this->assertSame($end, $input->case_end);
    }

    public function test_full_month_subset_of_partial_case_is_allowed_without_mutation(): void
    {
        $input = MonthlyCoverageRequest::from('2026-01-15', '2026-03-15', '2026-02-01', '2026-02-28');
        $before = get_object_vars($input);
        $result = (new MonthlyCoverageResolver)->resolve($input);
        $this->assertTrue($result->is_complete);
        $this->assertSame(['2026-02'], $result->months);
        $this->assertSame(1, $result->month_count);
        $this->assertSame($before, get_object_vars($input));
    }

    public static function rejectedRanges(): array
    {
        return [
            ['2026-01-01', '2026-12-31', '2026-02-01', '2026-02-29', 'INVALID_COVERAGE_DATE'],
            ['2026-01-01', '2026-12-31', '2026-03-31', '2026-01-01', 'INVALID_REQUESTED_RANGE'],
            ['2026-03-01', '2026-01-01', '2026-01-01', '2026-01-31', 'INVALID_CASE_RANGE'],
            ['2026-02-30', '2026-12-31', '2026-03-01', '2026-03-31', 'INVALID_COVERAGE_DATE'],
            ['2026-02-01', '2026-03-31', '2026-01-01', '2026-03-31', 'REQUESTED_RANGE_OUTSIDE_CASE'],
            ['2026-01-01', '2026-03-31', '2026-01-01', '2026-04-30', 'REQUESTED_RANGE_OUTSIDE_CASE'],
            ['2026-01-01', '2026-03-31', '2026-04-01', '2026-04-30', 'REQUESTED_RANGE_OUTSIDE_CASE'],
            ['2026-01-01', '2026-03-31', '2025-12-01', '2025-12-31', 'REQUESTED_RANGE_OUTSIDE_CASE'],
            ['2026-01-15', '2026-03-15', '2026-01-01', '2026-03-31', 'REQUESTED_RANGE_OUTSIDE_CASE'],
            // Containment rejection takes precedence over partial-boundary review.
            ['2026-01-15', '2026-03-15', '2026-01-14', '2026-03-14', 'REQUESTED_RANGE_OUTSIDE_CASE'],
        ];
    }

    #[DataProvider('rejectedRanges')]
    public function test_invalid_range_is_rejected_not_review(string $caseStart, string $caseEnd, string $start, string $end, string $code): void
    {
        try {
            MonthlyCoverageRequest::from($caseStart, $caseEnd, $start, $end);
            $this->fail('Expected rejection');
        } catch (ValidationException $error) {
            $this->assertSame([$code], array_merge(...array_values($error->errors())));
        }
    }

    public static function malformedDates(): array
    {
        return array_map(fn ($v) => [$v], ['', ' 2026-01-01', '2026-01-01 ', '2026-1-01', '01/01/2026',
            '2026-01-01T00:00:00Z', '2026-00-01', '2026-13-01', '2026-04-31', '0000-01-01', null, false, 20260101, 1.5]);
    }

    #[DataProvider('malformedDates')]
    public function test_all_date_fields_require_exact_valid_iso_strings(mixed $bad): void
    {
        foreach (range(0, 3) as $index) {
            $values = ['2026-01-01', '2026-12-31', '2026-02-01', '2026-02-28'];
            $values[$index] = $bad;
            try {
                MonthlyCoverageRequest::from(...$values);
                $this->fail('Expected invalid date');
            } catch (ValidationException $error) {
                $this->assertSame(['INVALID_COVERAGE_DATE'], array_merge(...array_values($error->errors())));
            }
        }
    }

    public function test_multiyear_partitions_are_ordered_contiguous_and_compatible_with_source_selection(): void
    {
        $result = (new MonthlyCoverageResolver)->resolve(MonthlyCoverageRequest::from('2024-01-01', '2026-12-31', '2024-11-01', '2026-02-28'));
        $this->assertSame(16, $result->month_count);
        $this->assertEquals([new SkpRequestedYearPartition(2024, 11, 12), new SkpRequestedYearPartition(2025, 1, 12),
            new SkpRequestedYearPartition(2026, 1, 2)], $result->year_partitions);
        $months = array_merge(...array_map(fn ($p) => $p->months(), $result->year_partitions));
        $this->assertSame($months, $result->months);
        $this->assertSame(count($months), count(array_unique($months)));
        $this->assertSame(['2024-11', '2024-12', '2025-01', '2025-02', '2025-03', '2025-04', '2025-05', '2025-06',
            '2025-07', '2025-08', '2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02'], $months);
    }

    public function test_calendar_year_and_no_artificial_36_month_cap(): void
    {
        $resolver = new MonthlyCoverageResolver;
        $year = $resolver->resolve(MonthlyCoverageRequest::from('2026-01-01', '2026-12-31', '2026-01-01', '2026-12-31'));
        $this->assertSame(12, $year->month_count);
        $long = $resolver->resolve(MonthlyCoverageRequest::from('2023-01-01', '2026-12-31', '2023-01-01', '2026-12-31'));
        $this->assertSame(48, $long->month_count);
        $this->assertCount(4, $long->year_partitions);
    }

    public function test_resolution_needs_no_database_and_is_timezone_independent(): void
    {
        DB::shouldReceive('connection')->never();
        $original = date_default_timezone_get();
        try {
            $input = MonthlyCoverageRequest::from('2024-02-01', '2024-02-29', '2024-02-01', '2024-02-29');
            date_default_timezone_set('Pacific/Kiritimati');
            $first = (new MonthlyCoverageResolver)->resolve($input);
            date_default_timezone_set('America/Los_Angeles');
            $this->assertEquals($first, (new MonthlyCoverageResolver)->resolve($input));
        } finally {
            date_default_timezone_set($original);
        }
    }

    public static function immutableFields(): array
    {
        return [['input'], ['result'], ['months'], ['partition']];
    }

    #[DataProvider('immutableFields')]
    public function test_input_and_output_are_immutable(string $target): void
    {
        $input = MonthlyCoverageRequest::from('2026-01-01', '2026-03-31', '2026-01-01', '2026-03-31');
        $result = (new MonthlyCoverageResolver)->resolve($input);
        $this->expectException(Error::class);
        match ($target) {
            'input' => $input->requested_start = '2026-01-15',
            'result' => $result->requested_end = '2026-03-15',
            'months' => $result->months[0] = '2026-02',
            'partition' => $result->year_partitions[0]->start_month = 2,
        };
    }

    public function test_empty_month_list_cannot_represent_complete_zero_month_coverage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MonthlyCoverage::complete(MonthlyCoverageRequest::from('2026-01-01', '2026-01-31', '2026-01-01', '2026-01-31'), []);
    }

    public function test_complete_factory_derives_months_and_count_from_partitions(): void
    {
        $request = MonthlyCoverageRequest::from('2025-12-01', '2026-02-28', '2025-12-01', '2026-02-28');
        $partitions = [new SkpRequestedYearPartition(2025, 12, 12), new SkpRequestedYearPartition(2026, 1, 2)];
        $result = MonthlyCoverage::complete($request, $partitions);
        $this->assertTrue($result->is_complete);
        $this->assertSame(['2025-12', '2026-01', '2026-02'], $result->months);
        $this->assertSame(array_merge(...array_map(fn ($p) => $p->months(), $partitions)), $result->months);
        $this->assertSame(3, $result->month_count);
        $this->assertSame($partitions, $result->year_partitions);
        $this->assertSame($request->requested_start, $result->requested_start);
        $this->assertSame($request->requested_end, $result->requested_end);
        $this->assertSame([], $result->issues);
    }

    public static function contradictoryPartitions(): array
    {
        return [
            'wrong first month' => ['2026-01-01', '2026-01-31', [[2026, 2, 2]]],
            'missing last month' => ['2026-01-01', '2026-03-31', [[2026, 1, 2]]],
            'extra month' => ['2026-01-01', '2026-01-31', [[2026, 1, 2]]],
            'duplicate' => ['2026-01-01', '2026-01-31', [[2026, 1, 1], [2026, 1, 1]]],
            'reordered' => ['2025-12-01', '2026-01-31', [[2026, 1, 1], [2025, 12, 12]]],
            'gap' => ['2025-12-01', '2026-02-28', [[2025, 12, 12], [2026, 2, 2]]],
            'partial start' => ['2026-01-15', '2026-01-31', [[2026, 1, 1]]],
            'partial end' => ['2026-01-01', '2026-01-15', [[2026, 1, 1]]],
        ];
    }

    #[DataProvider('contradictoryPartitions')]
    public function test_complete_factory_rejects_inconsistent_partitions_or_boundaries(string $start, string $end, array $partitions): void
    {
        $request = MonthlyCoverageRequest::from($start, $end, $start, $end);
        $this->expectException(InvalidArgumentException::class);
        MonthlyCoverage::complete($request, array_map(fn ($p) => new SkpRequestedYearPartition(...$p), $partitions));
    }

    public function test_partial_factory_preserves_dates_and_always_supplies_issue(): void
    {
        $request = MonthlyCoverageRequest::from('2026-01-15', '2026-03-15', '2026-01-15', '2026-03-15');
        $result = MonthlyCoverage::partialReview($request);
        $this->assertFalse($result->is_complete);
        $this->assertSame($request->requested_start, $result->requested_start);
        $this->assertSame($request->requested_end, $result->requested_end);
        $this->assertNull($result->months);
        $this->assertNull($result->month_count);
        $this->assertNull($result->year_partitions);
        $this->assertSame([['issue_code' => 'PARTIAL_MONTH_REQUIRES_REVIEW']], $result->issues);
    }

    public function test_partial_factory_rejects_full_month_request(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MonthlyCoverage::partialReview(MonthlyCoverageRequest::from('2026-01-01', '2026-01-31', '2026-01-01', '2026-01-31'));
    }

    public function test_arbitrary_contradictory_state_cannot_be_constructed_directly(): void
    {
        $this->expectException(Error::class);
        new MonthlyCoverage('2026-01-01', '2026-01-31', ['2026-01'], [new SkpRequestedYearPartition(2026, 2, 2)], []);
    }
}
