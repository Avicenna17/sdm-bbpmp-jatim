<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\SkpPositionMonthContext;
use App\Domain\Pak\DTOs\SkpRequestedYearPartition;
use App\Domain\Pak\DTOs\SkpSourceIdentityEvidence;
use App\Domain\Pak\DTOs\SkpSourceRowData;
use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;
use App\Domain\Pak\Services\SkpFingerprintSerializer;
use App\Domain\Pak\Services\SkpSourceSelectionResolver;
use App\Models\EmployeePositionHistory;
use App\Models\PakEvent;
use App\Models\PakRuleSet;
use App\Models\PerformanceEvaluation;
use App\Models\Person;
use App\Models\User;
use Error;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SkpSourceSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Person $person;

    private User $actor;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->person = Person::factory()->create();
        $this->actor = User::factory()->create();
    }

    private function evaluation(string $type = 'ANNUAL', array $overrides = [], ?int $historyId = null, ?int $previousId = null): PerformanceEvaluation
    {
        $values = array_replace([
            'source_kind' => PerformanceSourceKind::OFFICIAL, 'evaluation_type' => PerformanceEvaluationType::from($type),
            'performance_year' => 2026, 'source_reference' => 'SKP/synthetic', 'source_record_identifier' => 'record-'.++$this->sequence,
            'predicate_code' => 'BAIK', 'predicate_label_original' => 'Baik',
            'coverage_start' => '2026-01-01', 'coverage_end' => '2026-12-31',
        ], $overrides);
        $personId = $values['person_id'] ?? $this->person->id;
        unset($values['person_id']);
        $values['stable_identifier_evidence'] = new SkpSourceIdentityEvidence('synthetic-v1', 'record-code', $values['source_record_identifier']);
        $serializer = app(SkpFingerprintSerializer::class);
        $record = $serializer->normalize(new SkpSourceRowData(...$values), $personId);
        $attributes = json_decode($serializer->contentPayload($record), true);
        unset($attributes['schema_version'], $attributes['source_schema_version']);
        $hash = $serializer->sourceIdentityFingerprint($record);

        return PerformanceEvaluation::create($attributes + ['source_identity_fingerprint' => $hash,
            'current_source_identity_fingerprint' => $hash, 'content_fingerprint' => $serializer->contentFingerprint($record),
            'created_by' => $this->actor->id, 'employee_position_history_id' => $historyId,
            'previous_version_id' => $previousId, 'revision_reason' => $previousId === null ? null : 'Synthetic correction']);
    }

    private function history(array $overrides = []): EmployeePositionHistory
    {
        return EmployeePositionHistory::create(array_replace([
            'person_id' => $this->person->id, 'position_name' => 'Synthetic position', 'position_type' => 'JA',
            'tmt_start' => '2026-01-01', 'sk_number' => 'SK/01', 'sk_date' => '2025-12-01',
            'source' => 'MANUAL_VERIFIED', 'source_ref' => 'History/document', 'verified_at' => now(),
            'verified_by' => $this->actor->id, 'created_by' => $this->actor->id,
        ], $overrides));
    }

    private function resolve(int $end = 10, ?SkpPositionMonthContext $context = null, int $start = 1)
    {
        return app(SkpSourceSelectionResolver::class)->resolve($this->person->id, new SkpRequestedYearPartition(2026, $start, $end), $context);
    }

    private function assertIssue(string $code, $result): void
    {
        $this->assertSame([], $result->selections);
        $this->assertNotEmpty($result->issues);
        $this->assertSame([$code], array_values(array_unique(array_column($result->issues, 'issue_code'))));
    }

    public static function priorityCases(): array
    {
        return [
            'Q1' => [['Q1'], 3, 'Q1'], 'Q2 Jan-May' => [['Q1', 'Q2'], 5, 'Q2'],
            'Q3 not relevant' => [['Q3'], 5, null], 'Q3 Jan-Oct' => [['Q1', 'Q2', 'Q3'], 10, 'Q3'],
            'Q4 Jan-Oct' => [['Q3', 'Q4'], 10, 'Q4'], 'annual wins' => [['Q1', 'Q2', 'Q3', 'Q4', 'ANNUAL'], 10, 'ANNUAL'],
            'annual subset' => [['Q2', 'ANNUAL'], 5, 'ANNUAL'],
        ];
    }

    #[DataProvider('priorityCases')]
    public function test_active_priority_and_quarter_relevance(array $types, int $end, ?string $expected): void
    {
        foreach ($types as $type) {
            $this->evaluation($type);
        }
        $result = $this->resolve($end);
        if ($expected === null) {
            $this->assertIssue('SOURCE_NOT_FOUND', $result);
        } else {
            $this->assertSame([], $result->issues);
            $this->assertCount($end, $result->selections);
            $this->assertSame([$expected], array_values(array_unique(array_map(fn ($s) => $s->evaluation_type->value, $result->selections))));
        }
    }

    public static function excludedCases(): array
    {
        return [['provisional'], ['custom'], ['superseded'], ['person'], ['year']];
    }

    #[DataProvider('excludedCases')]
    public function test_ineligible_and_historical_sources_are_retained_but_excluded(string $case): void
    {
        $overrides = match ($case) {
            'provisional' => ['source_kind' => PerformanceSourceKind::PROVISIONAL],
            'person' => ['person_id' => Person::factory()->create()->id],
            'year' => ['performance_year' => 2025, 'coverage_start' => '2025-01-01', 'coverage_end' => '2025-12-31'],
            default => [],
        };
        $row = $this->evaluation($case === 'custom' ? 'CUSTOM_PERIOD' : 'ANNUAL', $overrides);
        if ($case === 'superseded') {
            $row->update(['status' => 'SUPERSEDED', 'current_source_identity_fingerprint' => null,
                'superseded_at' => now(), 'superseded_by_user_id' => $this->actor->id]);
        }
        $before = $row->fresh()->getAttributes();
        $this->assertIssue('SOURCE_NOT_FOUND', $this->resolve());
        $this->assertSame($before, $row->fresh()->getAttributes());
    }

    public function test_source_date_and_original_coverage_do_not_limit_applied_months(): void
    {
        $row = $this->evaluation('Q3', ['source_date' => '2027-04-01', 'coverage_start' => '2026-07-01', 'coverage_end' => '2026-09-30']);
        $result = $this->resolve();
        $this->assertSame([], $result->issues);
        $this->assertSame('2026-01', $result->selections[0]->applied_months[0]);
        $this->assertSame('2026-10', $result->selections[9]->applied_months[0]);
        $this->assertSame('2026-07-01', $result->selections[0]->coverage_start);
        $this->assertSame('2026-09-30', $result->selections[0]->coverage_end);
        $this->assertSame('2027-04-01', $result->selections[0]->source_date);
        $this->assertSame($row->content_fingerprint, $result->selections[0]->content_fingerprint);
    }

    public function test_single_annual_needs_no_history_and_dto_is_immutable(): void
    {
        $row = $this->evaluation();
        $selection = $this->resolve(12, start: 11)->selections[0];
        $this->assertSame($row->id, $selection->evaluation_id);
        $this->assertNull($selection->history_id);
        $this->assertSame('ANNUAL_PRIORITY', $selection->selection_reason);
        $this->expectException(Error::class);
        $selection->predicate_code = 'CHANGED';
    }

    public static function orders(): array
    {
        return [['ANNUAL', false], ['ANNUAL', true], ['Q1', false], ['Q1', true]];
    }

    #[DataProvider('orders')]
    public function test_top_priority_ambiguity_never_uses_insertion_order(string $type, bool $reverse): void
    {
        $history = $this->history();
        foreach ($reverse ? ['B', 'A'] : ['A', 'B'] as $code) {
            $this->evaluation($type, ['predicate_code' => $code], $history->id);
        }
        $this->assertIssue('AMBIGUOUS_SOURCE_SELECTION', $this->resolve(1, new SkpPositionMonthContext(['2026-01' => $history->id])));
    }

    #[DataProvider('booleanCases')]
    public function test_multiple_annual_uses_resolved_month_context_without_resolving_positions(bool $reverse): void
    {
        $early = $this->history();
        $late = $this->history(['tmt_start' => '2026-10-25', 'sk_number' => 'SK/02']);
        $rows = [];
        foreach ($reverse ? [$late, $early] : [$early, $late] as $history) {
            $rows[$history->id] = $this->evaluation(historyId: $history->id);
        }
        $result = $this->resolve(10, new SkpPositionMonthContext(['2026-09' => $early->id, '2026-10' => $late->id]), 9);
        $this->assertSame([], $result->issues);
        $this->assertSame([$rows[$early->id]->id, $rows[$late->id]->id], array_column($result->selections, 'evaluation_id'));
        $this->assertSame('EXPLICIT_HISTORY_ID', $result->selections[0]->history_proof_method);
    }

    public static function booleanCases(): array
    {
        return [[false], [true]];
    }

    public static function brokenLinks(): array
    {
        return [['wrong-person'], ['superseded'], ['number'], ['date'], ['tmt'], ['missing']];
    }

    #[DataProvider('brokenLinks')]
    public function test_explicit_invalid_history_never_falls_back_to_reconstruction(string $case): void
    {
        $good = $this->history();
        $bad = $this->history(match ($case) {
            'wrong-person' => ['person_id' => Person::factory()->create()->id],
            'superseded' => ['superseded_at' => now()], 'number' => ['sk_number' => 'OTHER'],
            'date' => ['sk_date' => '2025-12-02'], 'tmt' => ['tmt_start' => '2026-02-01'], default => [],
        });
        $row = $this->evaluation(overrides: ['source_position_tmt' => '2026-01-01', 'source_sk_number' => 'SK/01', 'source_sk_date' => '2025-12-01']);
        // Deliberately corrupt the FK only inside this rollback-isolated test.
        if ($case === 'missing') {
            $badId = 999999;
            DB::statement('PRAGMA defer_foreign_keys = ON');
        } else {
            $badId = $bad->id;
        }
        DB::table('performance_evaluations')->where('id', $row->id)->update(['employee_position_history_id' => $badId]);
        $this->assertIssue('SOURCE_POSITION_LINK_REQUIRES_REVIEW', $this->resolve(1, new SkpPositionMonthContext(['2026-01' => $good->id])));
    }

    public function test_exact_reconstruction_preserves_method_and_accepts_unrelated_document_references(): void
    {
        $history = $this->history();
        $other = $this->history(['tmt_start' => '2026-10-01']);
        $this->evaluation(historyId: $other->id);
        $this->evaluation(overrides: ['source_position_tmt' => '2026-01-01', 'source_sk_number' => 'SK/01', 'source_sk_date' => '2025-12-01']);
        $result = $this->resolve(1, new SkpPositionMonthContext(['2026-01' => $history->id]));
        $this->assertSame([], $result->issues);
        $this->assertSame($history->id, $result->selections[0]->history_id);
        $this->assertSame('EXACT_TMT_PROVENANCE', $result->selections[0]->history_proof_method);
    }

    public static function reconstructionFailures(): array
    {
        return [['zero'], ['multiple'], ['name-only'], ['fuzzy'], ['sk-mismatch']];
    }

    #[DataProvider('reconstructionFailures')]
    public function test_reconstruction_requires_unique_exact_tmt_and_consistent_provenance(string $case): void
    {
        $history = $this->history();
        $other = $this->history(['tmt_start' => '2026-10-01']);
        $this->evaluation(historyId: $other->id);
        if ($case === 'multiple') {
            $this->history();
        }
        $values = ['source_position_tmt' => '2026-01-01'];
        if ($case === 'zero') {
            $values['source_position_tmt'] = '2026-01-02';
        } elseif ($case === 'name-only') {
            $values = ['source_position_name' => 'Synthetic position'];
        } elseif ($case === 'fuzzy') {
            $values['source_position_name'] = 'synthetic position';
        } elseif ($case === 'sk-mismatch') {
            $values['source_sk_number'] = 'sk/01';
        }
        $this->evaluation(overrides: $values);
        $this->assertIssue('SOURCE_POSITION_LINK_REQUIRES_REVIEW', $this->resolve(1, new SkpPositionMonthContext(['2026-01' => $history->id])));
    }

    public function test_multiple_annual_with_unproven_link_requires_review_without_quarter_fallback(): void
    {
        $this->evaluation();
        $this->evaluation();
        $this->evaluation('Q4');
        $this->assertIssue('SOURCE_POSITION_LINK_REQUIRES_REVIEW', $this->resolve());
    }

    public function test_projection_corruption_is_an_integrity_issue_without_quarter_fallback(): void
    {
        $row = $this->evaluation();
        $this->evaluation('Q4');
        DB::table('performance_evaluations')->where('id', $row->id)->update(['current_source_identity_fingerprint' => str_repeat('f', 64)]);
        $this->assertIssue('SOURCE_INTEGRITY_ERROR', $this->resolve());
    }

    public function test_label_cannot_disambiguate_multiple_exact_tmt_document_matches(): void
    {
        $history = $this->history();
        $other = $this->history(['tmt_start' => '2026-10-01']);
        $this->evaluation(historyId: $other->id);
        $this->history(['position_name' => 'Other label']);
        $this->evaluation(overrides: ['source_position_tmt' => '2026-01-01', 'source_sk_number' => 'SK/01', 'source_position_name' => 'Synthetic position']);
        $this->assertIssue('SOURCE_POSITION_LINK_REQUIRES_REVIEW', $this->resolve(1, new SkpPositionMonthContext(['2026-01' => $history->id])));
    }

    public function test_current_successor_is_selected_instead_of_historical_predecessor(): void
    {
        $old = $this->evaluation();
        $old->update(['status' => 'SUPERSEDED', 'current_source_identity_fingerprint' => null,
            'superseded_at' => now(), 'superseded_by_user_id' => $this->actor->id]);
        $next = $this->evaluation(overrides: ['predicate_code' => 'SANGAT_BAIK', 'source_record_identifier' => $old->source_record_identifier], previousId: $old->id);
        $this->assertSame($next->id, $this->resolve(1)->selections[0]->evaluation_id);
        $this->assertSame('SANGAT_BAIK', $this->resolve(1)->selections[0]->predicate_code);
    }

    public static function corruptValues(): array
    {
        return [['content_fingerprint', 'bad'], ['predicate_code', ''], ['source_record_identifier', null], ['coverage_end', '2026-02-30'],
            ['source_date', 'invalid'], ['source_position_tmt', '2026-02-30'], ['source_sk_date', 'not-a-date']];
    }

    #[DataProvider('corruptValues')]
    public function test_persisted_integrity_errors_are_review_results(string $field, ?string $value): void
    {
        $row = $this->evaluation();
        DB::table('performance_evaluations')->where('id', $row->id)->update([$field => $value]);
        $this->assertIssue('SOURCE_INTEGRITY_ERROR', $this->resolve());
    }

    public function test_verified_multiple_annual_without_position_context_is_ambiguous(): void
    {
        $first = $this->history();
        $second = $this->history(['tmt_start' => '2026-10-01']);
        $this->evaluation(historyId: $first->id);
        $this->evaluation(historyId: $second->id);
        $this->assertIssue('AMBIGUOUS_SOURCE_SELECTION', $this->resolve());
    }

    public function test_selection_has_no_writes_including_audit_and_first_use(): void
    {
        $history = $this->history();
        $row = $this->evaluation(historyId: $history->id);
        $rule = PakRuleSet::create(['code' => 'SYNTHETIC', 'name' => 'Synthetic', 'version' => '1', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $before = [$row->fresh()->getAttributes(), $history->fresh()->getAttributes(), $rule->fresh()->getAttributes(), PakEvent::count()];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = $this->resolve();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $result->issues);
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/\Aselect\b/i', $query['query']);
        }
        $this->assertSame($before, [$row->fresh()->getAttributes(), $history->fresh()->getAttributes(), $rule->fresh()->getAttributes(), PakEvent::count()]);
    }

    public function test_invalid_partition_is_not_repaired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SkpRequestedYearPartition(2026, 10, 5);
    }

    public function test_single_annual_without_history_stays_selected_when_position_context_is_supplied(): void
    {
        $source = $this->evaluation();
        $context = new SkpPositionMonthContext(['2026-01' => $this->history()->id]);
        $result = $this->resolve(10, $context);
        $this->assertSame([], $result->issues);
        $this->assertCount(10, $result->selections);
        $this->assertEquals($this->resolve(), $result);
        foreach ($result->selections as $selection) {
            $this->assertSame($source->id, $selection->evaluation_id);
            $this->assertNull($selection->history_id);
            $this->assertNull($selection->history_proof_method);
        }
    }

    public function test_single_quarter_without_history_stays_selected_when_position_context_is_supplied(): void
    {
        $source = $this->evaluation('Q3');
        $result = $this->resolve(10, new SkpPositionMonthContext(['2026-01' => $this->history()->id]));
        $this->assertSame([], $result->issues);
        $this->assertCount(10, $result->selections);
        $this->assertEquals($this->resolve(), $result);
        foreach ($result->selections as $selection) {
            $this->assertSame($source->id, $selection->evaluation_id);
            $this->assertNull($selection->history_id);
        }
    }

    #[DataProvider('singleSourceTypes')]
    public function test_single_source_with_valid_explicit_history_is_not_position_filtered(string $type): void
    {
        $history = $this->history();
        $other = $this->history(['tmt_start' => '2026-10-01']);
        $source = $this->evaluation($type, historyId: $history->id);
        $result = $this->resolve(10, new SkpPositionMonthContext(['2026-09' => $history->id, '2026-10' => $other->id]), 9);
        $this->assertSame([], $result->issues);
        $this->assertCount(2, $result->selections);
        $this->assertEquals($this->resolve(10, start: 9), $result);
        foreach ($result->selections as $selection) {
            $this->assertSame($source->id, $selection->evaluation_id);
            $this->assertSame($history->id, $selection->history_id);
            $this->assertSame('EXPLICIT_HISTORY_ID', $selection->history_proof_method);
        }
    }

    public static function singleSourceTypes(): array
    {
        return [['ANNUAL'], ['Q3']];
    }
}
