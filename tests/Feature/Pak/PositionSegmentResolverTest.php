<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\MonthlyCoverageRequest;
use App\Domain\Pak\DTOs\PositionResolution;
use App\Domain\Pak\Services\MonthlyCoverageResolver;
use App\Domain\Pak\Services\PositionSegmentResolver;
use App\Models\EmployeePositionHistory;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\Person;
use App\Models\User;
use Error;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PositionSegmentResolverTest extends TestCase
{
    use RefreshDatabase;

    private Person $person;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->person = Person::factory()->create();
        $this->actor = User::factory()->create();
    }

    /** Direct isolated fixtures intentionally permit overlaps rejected by EmployeeHistoryService. */
    private function history(string $start, ?string $end = null, array $extra = []): EmployeePositionHistory
    {
        return EmployeePositionHistory::create(array_replace([
            'person_id' => $this->person->id, 'position_name' => 'Synthetic position', 'position_type' => 'JA',
            'tmt_start' => $start, 'tmt_end' => $end, 'sk_number' => 'SK/synthetic', 'sk_date' => '2025-12-01',
            'source' => 'MANUAL_VERIFIED', 'source_ref' => 'Synthetic/document',
            'verified_at' => '2026-01-01 10:00:00', 'verified_by' => $this->actor->id, 'created_by' => $this->actor->id,
        ], $extra));
    }

    private function resolve(array $months = ['2026-10'])
    {
        return (new PositionSegmentResolver)->resolve($this->person->id, $months);
    }

    #[DataProvider('validIntervals')]
    public function test_complete_month_uses_latest_tmt_only_after_continuity_proof(array $intervals, string $month): void
    {
        $histories = [];
        foreach ($intervals as [$start, $end]) {
            $histories[] = $this->history($start, $end);
        }
        $result = $this->resolve([$month]);
        $this->assertTrue($result->is_complete);
        $this->assertSame([], $result->issues);
        $this->assertCount(1, $result->assignments);
        $this->assertSame(end($histories)->id, $result->assignments[0]->history_id);
        $this->assertSame(array_map(fn ($h) => $h->id, $histories), $result->assignments[0]->coverage_history_ids);
        $this->assertSame('LATEST_APPLICABLE_TMT_AFTER_CONTINUITY_PROOF', $result->assignments[0]->selection_reason);
        $this->assertSame([$month => end($histories)->id], $result->positionContext()->history_by_month);
    }

    public static function validIntervals(): array
    {
        return [
            'open-ended' => [[['2026-01-01', null]], '2026-10'],
            'exact month' => [[['2026-10-01', '2026-10-31']], '2026-10'],
            'ends month-end' => [[['2026-01-01', '2026-10-31']], '2026-10'],
            'first' => [[['2026-10-01', null]], '2026-10'],
            'fifteenth' => [[['2026-01-01', '2026-10-14'], ['2026-10-15', null]], '2026-10'],
            'last day' => [[['2026-01-01', '2026-10-30'], ['2026-10-31', null]], '2026-10'],
            'several transitions' => [[['2026-01-01', '2026-10-14'], ['2026-10-15', '2026-10-24'], ['2026-10-25', null]], '2026-10'],
            'leap february' => [[['2024-02-01', '2024-02-28'], ['2024-02-29', null]], '2024-02'],
            'nonleap february' => [[['2026-02-01', '2026-02-28']], '2026-02'],
        ];
    }

    #[DataProvider('invalidCoverage')]
    public function test_gap_and_overlap_have_exact_intervals_and_no_assignment(array $intervals, string $code, string $start, string $end): void
    {
        $ids = [];
        foreach ($intervals as [$from, $until]) {
            $ids[] = $this->history($from, $until)->id;
        }
        $result = $this->resolve();
        $this->assertFalse($result->is_complete);
        $this->assertSame([], $result->assignments);
        $this->assertSame([], $result->segments);
        $this->assertSame([], $result->positionContext()->history_by_month);
        $this->assertSame([['issue_code' => $code, 'month' => '2026-10', 'interval_start' => $start,
            'interval_end' => $end, 'history_ids' => $code === 'AMBIGUOUS_POSITION_HISTORY' ? $ids : []]], $result->issues);
    }

    public static function invalidCoverage(): array
    {
        return [
            'none' => [[], 'MISSING_POSITION_HISTORY', '2026-10-01', '2026-10-31'],
            'ended before' => [[['2026-01-01', '2026-09-30']], 'MISSING_POSITION_HISTORY', '2026-10-01', '2026-10-31'],
            'tail gap' => [[['2026-01-01', '2026-10-10']], 'MISSING_POSITION_HISTORY', '2026-10-11', '2026-10-31'],
            'interior gap' => [[['2026-01-01', '2026-10-10'], ['2026-10-20', null]], 'MISSING_POSITION_HISTORY', '2026-10-11', '2026-10-19'],
            'leading gap' => [[['2026-01-01', '2026-09-30'], ['2026-10-15', null]], 'MISSING_POSITION_HISTORY', '2026-10-01', '2026-10-14'],
            'future not backward' => [[['2026-11-01', null]], 'MISSING_POSITION_HISTORY', '2026-10-01', '2026-10-31'],
            'same inclusive date' => [[['2026-01-01', '2026-10-15'], ['2026-10-15', null]], 'AMBIGUOUS_POSITION_HISTORY', '2026-10-15', '2026-10-15'],
            'overlap' => [[['2026-01-01', '2026-10-20'], ['2026-10-15', null]], 'AMBIGUOUS_POSITION_HISTORY', '2026-10-15', '2026-10-20'],
            'open predecessor' => [[['2026-01-01', null], ['2026-10-15', null]], 'AMBIGUOUS_POSITION_HISTORY', '2026-10-15', '2026-10-31'],
            'equal TMT' => [[['2026-10-01', null], ['2026-10-01', null]], 'AMBIGUOUS_POSITION_HISTORY', '2026-10-01', '2026-10-31'],
        ];
    }

    public function test_superseded_and_wrong_person_histories_cannot_fill_a_gap(): void
    {
        $this->history('2026-01-01', null, ['superseded_at' => '2026-10-01 00:00:00']);
        $this->history('2026-01-01', null, ['person_id' => Person::factory()->create()->id]);
        $result = $this->resolve();
        $this->assertFalse($result->is_complete);
        $this->assertSame('MISSING_POSITION_HISTORY', $result->issues[0]['issue_code']);
        $this->assertSame([], $result->assignments);
    }

    public function test_query_explicitly_excludes_unverified_rows_without_weakening_not_null_schema(): void
    {
        // Schema requires both verification fields; inspect the actual eligibility query instead of dropping constraints.
        DB::enableQueryLog();
        $this->resolve();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $sql = $queries[0]['query'];
        $this->assertStringContainsString('"verified_by" is not null', $sql);
        $this->assertStringContainsString('"verified_at" is not null', $sql);
        $this->assertStringContainsString('"superseded_at" is null', $sql);
    }

    public function test_monthly_coverage_integration_segments_and_source_context(): void
    {
        $a = $this->history('2025-01-01', '2025-12-31');
        $b = $this->history('2026-01-01', '2026-02-28');
        $coverage = (new MonthlyCoverageResolver)->resolve(MonthlyCoverageRequest::from('2025-01-01', '2026-12-31', '2025-12-01', '2026-03-31'));
        $result = $this->resolve($coverage->months);
        $this->assertFalse($result->is_complete);
        $this->assertSame(['2025-12', '2026-01', '2026-02'], array_column($result->assignments, 'month'));
        $this->assertSame(['2025-12' => $a->id, '2026-01' => $b->id, '2026-02' => $b->id], $result->positionContext()->history_by_month);
        $this->assertCount(2, $result->segments);
        $this->assertSame(['2026-01', '2026-02'], $result->segments[1]->months);
        $this->assertSame(2, $result->segments[1]->month_count);
        $this->assertSame('2026-01', $result->segments[1]->start_month);
        $this->assertSame('2026-02', $result->segments[1]->end_month);
        $this->assertSame('2026-03', $result->issues[0]['month']);
    }

    public function test_reverse_insertion_order_does_not_change_priority_or_ambiguity(): void
    {
        $later = $this->history('2026-10-15');
        $earlier = $this->history('2026-01-01', '2026-10-14');
        $this->assertGreaterThan($later->id, $earlier->id);
        $this->assertSame($later->id, $this->resolve()->assignments[0]->history_id);
        $this->history('2026-10-01', '2026-10-31');
        $result = $this->resolve();
        $this->assertSame([], $result->assignments);
        $this->assertSame(['AMBIGUOUS_POSITION_HISTORY'], array_values(array_unique(array_column($result->issues, 'issue_code'))));
    }

    public function test_one_month_can_report_both_gap_and_overlap_without_a_winner(): void
    {
        $a = $this->history('2026-10-05', '2026-10-20');
        $b = $this->history('2026-10-15');
        $result = $this->resolve();
        $this->assertFalse($result->is_complete);
        $this->assertSame([], $result->assignments);
        $this->assertSame([
            ['issue_code' => 'MISSING_POSITION_HISTORY', 'month' => '2026-10', 'interval_start' => '2026-10-01', 'interval_end' => '2026-10-04', 'history_ids' => []],
            ['issue_code' => 'AMBIGUOUS_POSITION_HISTORY', 'month' => '2026-10', 'interval_start' => '2026-10-15', 'interval_end' => '2026-10-20', 'history_ids' => [$a->id, $b->id]],
        ], $result->issues);
    }

    public function test_sparse_months_are_rejected_before_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->resolve(['2026-01', '2026-03']);
            $this->fail('Sparse months must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('INVALID_POSITION_RESOLUTION_INPUT', $e->getMessage());
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_segments_do_not_bridge_an_unresolved_middle_month(): void
    {
        $a = $this->history('2026-01-01');
        $this->history('2026-02-01', '2026-02-28');
        $result = $this->resolve(['2026-01', '2026-02', '2026-03']);
        $this->assertFalse($result->is_complete);
        $this->assertCount(2, $result->segments);
        $this->assertSame(['2026-01'], $result->segments[0]->months);
        $this->assertSame(['2026-03'], $result->segments[1]->months);
        $this->assertSame($a->id, $result->segments[0]->history_id);
        $this->assertSame($a->id, $result->segments[1]->history_id);
        $this->assertSame(['2026-01' => $a->id, '2026-03' => $a->id], $result->positionContext()->history_by_month);
        $this->assertSame('2026-02', $result->issues[0]['month']);
    }

    #[DataProvider('invalidResultStates')]
    public function test_result_rejects_contradictory_or_noncanonical_state(string $case): void
    {
        $this->history('2026-01-01');
        $valid = $this->resolve(['2026-01', '2026-02', '2026-03']);
        $months = $valid->months;
        $assignments = $valid->assignments;
        $issues = [];
        $issue = ['issue_code' => 'MISSING_POSITION_HISTORY', 'month' => '2026-02',
            'interval_start' => '2026-02-01', 'interval_end' => '2026-02-28', 'history_ids' => []];
        switch ($case) {
            case 'reverse assignments': $assignments = array_reverse($assignments);
                break;
            case 'foreign assignment': $months = ['2026-01', '2026-02'];
                break;
            case 'duplicate assignment': $assignments = [$assignments[0], $assignments[0], $assignments[2]];
                break;
            case 'wrong assignment type': $assignments[1] = new \stdClass;
                break;
            case 'assignment and issue': $issues = [$issue];
                break;
            case 'foreign issue': $issues = [array_replace($issue, ['month' => '2026-04'])];
                break;
            case 'noncanonical issue': $issues = [array_replace($issue, ['month' => '2026-2'])];
                break;
            case 'missing month': $assignments = [$assignments[0], $assignments[2]];
                break;
            case 'invalid issue shape': $issues = ['2026-02'];
                break;
            case 'empty months': $months = [];
                break;
            case 'associative months': $months = ['first' => '2026-01', 'second' => '2026-02', 'third' => '2026-03'];
                break;
            case 'sparse months': $months = ['2026-01', '2026-03'];
                $assignments = [$assignments[0], $assignments[2]];
                break;
            case 'reversed months': $months = array_reverse($months);
                break;
            case 'duplicate months': $months = ['2026-01', '2026-01', '2026-03'];
                break;
            case 'noncanonical months': $months[0] = '2026-1';
                break;
            case 'invalid year': $months[0] = '0000-01';
                break;
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('INVALID_POSITION_RESOLUTION');
        new PositionResolution($months, $assignments, $issues);
    }

    public static function invalidResultStates(): array
    {
        return array_map(fn ($case) => [$case], ['reverse assignments', 'foreign assignment', 'duplicate assignment',
            'wrong assignment type', 'assignment and issue', 'foreign issue', 'noncanonical issue', 'missing month',
            'invalid issue shape', 'empty months', 'associative months', 'sparse months', 'reversed months',
            'duplicate months', 'noncanonical months', 'invalid year']);
    }

    public function test_superseded_overlap_does_not_block_current_history(): void
    {
        $this->history('2026-01-01', null, ['superseded_at' => '2026-10-01 00:00:00']);
        $current = $this->history('2026-01-01');
        $result = $this->resolve();
        $this->assertTrue($result->is_complete);
        $this->assertSame($current->id, $result->assignments[0]->history_id);
    }

    public function test_result_and_segment_arrays_are_immutable(): void
    {
        $this->history('2026-01-01');
        $result = $this->resolve();
        try {
            $result->assignments = [];
            $this->fail('Result must be immutable');
        } catch (Error) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(Error::class);
        $result->segments[0]->months[] = '2026-11';
    }

    public function test_provenance_and_inactive_level_are_preserved_and_result_is_immutable(): void
    {
        $category = FunctionalCategory::create(['code' => 'TEST', 'name' => 'Synthetic', 'is_active' => false]);
        $level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'LEVEL', 'name' => 'Historical level', 'is_active' => false]);
        $history = $this->history('2026-01-01', '2026-10-31', ['functional_level_id' => $level->id]);
        $result = $this->resolve();
        $assignment = $result->assignments[0];
        $this->assertSame($history->id, $assignment->history_id);
        $this->assertSame($this->person->id, $assignment->person_id);
        $this->assertSame('2026-01-01', $assignment->tmt_start);
        $this->assertSame('2026-10-31', $assignment->tmt_end);
        $this->assertSame('SK/synthetic', $assignment->sk_number);
        $this->assertSame('2025-12-01', $assignment->sk_date);
        $this->assertSame('Synthetic/document', $assignment->source_ref);
        $this->assertSame('MANUAL_VERIFIED', $assignment->source);
        $this->assertSame($this->actor->id, $assignment->verified_by);
        $this->assertSame('2026-01-01 10:00:00', $assignment->verified_at);
        $this->assertSame('LEVEL', $assignment->functional_level_code);
        $this->assertSame('Historical level', $assignment->functional_level_name);
        $this->expectException(Error::class);
        $assignment->tmt_start = '2026-02-01';
    }

    public function test_resolution_is_deterministic_read_only_and_queries_do_not_grow_per_month(): void
    {
        $history = $this->history('2026-01-01');
        $before = $history->fresh()->getRawOriginal();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $first = $this->resolve(['2026-09', '2026-10', '2026-11']);
        $second = $this->resolve(['2026-09', '2026-10', '2026-11']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertEquals($first, $second);
        $this->assertCount(2, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
        }
        $this->assertSame($before, $history->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pak_events', 0);
        $this->assertDatabaseCount('employee_position_histories', 1);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected_before_queries(int $personId, array $months): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            (new PositionSegmentResolver)->resolve($personId, $months);
            $this->fail('Expected invalid input');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('INVALID_POSITION_RESOLUTION_INPUT', $e->getMessage());
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public static function invalidInputs(): array
    {
        return [[0, ['2026-10']], [1, []], [1, ['2026-1']], [1, ['0000-01']], [1, ['2026-13']],
            [1, ['2026-10', '2026-09']], [1, ['2026-10', '2026-10']], [1, [202610]], [1, [' 2026-10']],
            [1, ['month' => '2026-10']]];
    }
}
