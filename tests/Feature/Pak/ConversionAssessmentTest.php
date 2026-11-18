<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionAssessment;
use App\Domain\Pak\DTOs\ConversionAssessmentRequest;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionReplaceRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\Exceptions\ConversionRuleException;
use App\Domain\Pak\Services\ConversionAssessmentService;
use App\Domain\Pak\Services\ConversionManifestBuilder;
use App\Domain\Pak\Services\ConversionPreviewDependencies;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\FunctionalMasterService;
use App\Models\AkCoefficientRule;
use App\Models\EmployeePositionHistory;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use App\Models\PakCase;
use App\Models\PakConversionRun;
use App\Models\PakRuleSet;
use App\Models\PerformanceEvaluation;
use App\Models\PerformancePredicateRule;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ConversionAssessmentTest extends TestCase
{
    private User $actor;

    private PakCase $case;

    private PakRuleSet $rule;

    private EmployeePositionHistory $history;

    private FunctionalLevel $level;

    private ?string $denied = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        // Preview owns its transaction: use a fresh disposable schema without a wrapping test transaction.
        $this->artisan('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $this->actor = User::factory()->create(['is_active' => true]);
        Gate::before(fn ($user, $permission) => $permission !== $this->denied);
        // Entirely synthetic integration data. This is NOT Nurmaulida documentary evidence.
        $person = Person::factory()->create(['canonical_name' => 'Synthetic Preview Subject']);
        $category = FunctionalCategory::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        $this->level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'PREVIEW', 'name' => 'Synthetic']);
        $position = FunctionalPosition::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        FunctionalPositionLevel::create(['functional_position_id' => $position->id, 'functional_level_id' => $this->level->id]);
        $this->rule = PakRuleSet::create(['code' => 'PREVIEW', 'version' => '1', 'name' => 'Synthetic', 'valid_from' => '2023-01-01', 'valid_until' => '2026-12-31', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        PerformancePredicateRule::create(['pak_rule_set_id' => $this->rule->id, 'predicate_code' => 'BAIK', 'predicate_name' => 'Synthetic', 'predicate_factor' => '1']);
        AkCoefficientRule::create(['pak_rule_set_id' => $this->rule->id, 'functional_level_id' => $this->level->id, 'annual_coefficient' => '25']);
        // Isolated fixture stands in for accepted case first-use lifecycle, never production preview behavior.
        DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['first_used_at' => '2026-01-01 00:00:00']);
        $this->case = PakCase::create(['person_id' => $person->id, 'pak_rule_set_id' => $this->rule->id, 'purpose_type' => 'KP', 'period_start' => '2023-01-01', 'period_end' => '2026-12-31', 'employee_data_as_of' => '2026-01-01', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $this->history = EmployeePositionHistory::create(['person_id' => $person->id, 'position_name' => 'Synthetic position', 'position_type' => 'JF', 'functional_position_id' => $position->id, 'functional_level_id' => $this->level->id,
            'tmt_start' => '2023-01-01', 'sk_number' => 'SYNTHETIC-SK-001', 'sk_date' => '2022-12-15', 'source' => 'FIXTURE', 'source_ref' => 'Synthetic documentary reference', 'verified_by' => $this->actor->id, 'verified_at' => now(), 'created_by' => $this->actor->id]);
        $this->case->refresh();
        foreach ([2023, 2024, 2025, 2026] as $year) {
            $this->evaluation($year);
        }
    }

    private function evaluation(int $year, string $type = 'ANNUAL'): PerformanceEvaluation
    {
        $hash = hash('sha256', $year.$type.PerformanceEvaluation::count());

        return PerformanceEvaluation::create(['person_id' => $this->case->person_id, 'created_by' => $this->actor->id, 'performance_year' => $year,
            'evaluation_type' => $type, 'source_kind' => 'OFFICIAL', 'source_reference' => 'Synthetic-'.$hash, 'source_record_identifier' => $hash, 'predicate_code' => 'BAIK', 'predicate_label_original' => 'Synthetic',
            'coverage_start' => $year.'-01-01', 'coverage_end' => $year.'-12-31', 'source_identity_fingerprint' => $hash, 'content_fingerprint' => $hash, 'current_source_identity_fingerprint' => $hash]);
    }

    private function preview(string $start = '2023-11-01', string $end = '2026-10-31')
    {
        return app(ConversionService::class)->preview($this->actor, new ConversionPreviewRequest($this->case->id, $start, $end));
    }

    private function request(string $start = '2023-11-01', string $end = '2026-10-31'): ConversionSaveRequest
    {
        $preview = $this->preview($start, $end);

        return ConversionSaveRequest::from(['pak_case_id' => $this->case->id, 'requested_start' => $start, 'requested_end' => $end,
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint, 'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint]);
    }

    private function save(?ConversionSaveRequest $request = null): PakConversionRun
    {
        return app(ConversionService::class)->saveInitial($this->actor, $request ?? $this->request());
    }

    private function state(): array
    {
        $state = [];
        foreach (['people', 'pak_cases', 'pak_case_employee_snapshots', 'pak_conversion_runs', 'pak_conversion_periods',
            'pak_events', 'performance_evaluations', 'employee_position_histories', 'pak_rule_sets',
            'performance_predicate_rules', 'ak_coefficient_rules', 'functional_levels', 'functional_positions',
            'functional_categories', 'functional_position_levels'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $state;
    }

    private function clean(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertCount(0, app('db.transactions')->getPendingTransactions());
        $this->assertCount(0, app('db.transactions')->getCommittedTransactions());
    }

    /** All successful and failing semantic assessments must be read-only and clean. */
    private function assess(PakConversionRun $run): ConversionAssessment
    {
        $before = $this->state();
        $observing = true;
        $semanticLevels = [];
        DB::listen(function ($q) use (&$observing, &$semanticLevels) {
            if ($observing) {
                $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|for update|lock in share mode)\b/i', $q->sql);
                if (preg_match('/from "(pak_conversion_runs|pak_cases|pak_rule_sets|employee_position_histories|performance_evaluations)"/', $q->sql)) {
                    $semanticLevels[] = DB::transactionLevel();
                    $this->assertSame(1, DB::transactionLevel());
                }
            }
        });
        // SQLite omits SQL lock clauses: inspect builder intent too, without changing SQL.
        $connection = DB::connection();
        $originalGrammar = $connection->getQueryGrammar();
        $grammar = new class($connection) extends SQLiteGrammar
        {
            public array $locks = [];

            public function compileSelect(Builder $query)
            {
                if ($query->lock !== null) {
                    $this->locks[] = $query->from;
                }

                return parent::compileSelect($query);
            }
        };
        $connection->setQueryGrammar($grammar);
        $begins = 0;
        $connection->beforeStartingTransaction(function () use (&$observing, &$begins) {
            if ($observing) {
                $begins++;
            }
        });
        try {
            return app(ConversionAssessmentService::class)->assess($this->actor,
                ConversionAssessmentRequest::from(['pak_case_id' => $this->case->id, 'run_id' => $run->id]));
        } finally {
            $observing = false;
            $connection->setQueryGrammar($originalGrammar);
            $this->assertSame(1, $begins);
            $this->assertNotEmpty($semanticLevels);
            $this->assertSame([], $grammar->locks);
            $this->clean();
            $this->assertSame($before, $this->state());
        }
    }

    public function test_request_has_only_two_strict_positive_native_integer_ids(): void
    {
        $valid = ['pak_case_id' => 1, 'run_id' => 2];
        $this->assertSame($valid, get_object_vars(ConversionAssessmentRequest::from($valid)));
        $invalid = [[], ['pak_case_id' => 1], ['run_id' => 2]];
        foreach (['pak_case_id', 'run_id'] as $field) {
            foreach ([null, false, true, '1', 1.0, 0, -1, [], new \stdClass] as $value) {
                $invalid[] = array_replace($valid, [$field => $value]);
            }
        }
        foreach (['requested_start', 'requested_end', 'actor', 'status', 'hashes', 'manifest', 'issues', 'current_dependencies', 'selected_ids', 'reason'] as $key) {
            $invalid[] = $valid + [$key => 'not accepted'];
        }
        foreach ($invalid as $input) {
            try {
                ConversionAssessmentRequest::from($input);
                $this->fail('Invalid request accepted.');
            } catch (ValidationException $error) {
                $this->assertSame(['request'], array_keys($error->errors()));
            }
        }
    }

    public function test_unchanged_output_and_exact_token_are_immutable_deterministic_and_read_only(): void
    {
        $run = $this->save();
        $first = $this->assess($run);
        $this->assertSame('UNCHANGED', $first->comparison);
        $this->assertSame([], $first->changed_groups);
        $this->assertSame([], $first->current_issue_codes);
        $this->assertNull($first->failure_code);
        $this->assertTrue($first->is_current_active);
        $this->assertSame('PREVIEW', $first->observed_run_status);
        $this->assertSame(0, $first->assessment_sequence);
        $this->assertSame($run->case_lock_version, $first->observed_case_lock_version);
        $this->assertSame($run->dependency_fingerprint, $first->current_dependency_fingerprint);
        $this->assertSame($run->snapshot_fingerprint, $first->current_snapshot_fingerprint);
        $keys = ['pak_case_id', 'run_id', 'observed_run_status', 'observed_case_lock_version', 'assessment_sequence', 'comparison',
            'current_dependency_fingerprint', 'current_snapshot_fingerprint', 'changed_groups', 'current_issue_codes', 'failure_code'];
        $values = get_object_vars($first);
        $this->assertEqualsCanonicalizing([...$keys, 'revision_number', 'is_current_active', 'saved_dependency_fingerprint', 'saved_snapshot_fingerprint', 'assessment_fingerprint'], array_keys($values));
        $this->assertSame(ConversionManifestBuilder::hash(array_intersect_key($values, array_flip($keys))), $first->assessment_fingerprint);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $first->assessment_fingerprint);
        $this->travel(2)->hours();
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->assertSame($values, get_object_vars($this->assess($run)));
        $this->expectException(\Error::class);
        $first->comparison = 'CHANGED';
    }

    public function test_unchanged_diagnostic_run_is_comparable_and_keeps_original_issues(): void
    {
        $run = $this->save($this->request('2023-11-02'));
        $result = $this->assess($run);
        $this->assertSame('REQUIRES_REVIEW', $result->observed_run_status);
        $this->assertSame('UNCHANGED', $result->comparison);
        $this->assertSame(['PARTIAL_MONTH_REQUIRES_REVIEW'], $result->current_issue_codes);
        $this->assertSame($run->snapshot_fingerprint, $result->current_snapshot_fingerprint);
    }

    public static function changes(): array
    {
        return [['evaluation', 'source_candidates'], ['history', 'history_dependencies'], ['level', 'history_dependencies'],
            ['rule', 'rule_set'], ['case', 'case_context']];
    }

    #[DataProvider('changes')]
    public function test_semantic_change_compares_canonical_groups(string $kind, string $expectedGroup): void
    {
        $run = $this->save();
        match ($kind) {
            'evaluation' => DB::table('performance_evaluations')->where('performance_year', 2025)->update(['content_fingerprint' => str_repeat('e', 64)]),
            'history' => DB::table('employee_position_histories')->where('id', $this->history->id)->update(['superseded_at' => '2026-02-01 00:00:00']),
            'level' => $this->renameLevel(),
            'rule' => DB::table('ak_coefficient_rules')->where('pak_rule_set_id', $this->rule->id)->update(['annual_coefficient' => '37.5']),
            'case' => DB::table('pak_cases')->where('id', $this->case->id)->update(['purpose_type' => 'KJ']),
        };
        $result = $this->assess($run);
        $preview = $this->preview();
        $this->assertSame('CHANGED', $result->comparison);
        $this->assertContains($expectedGroup, $result->changed_groups);
        $expected = [];
        foreach (['case_context', 'coverage', 'source_candidates', 'selected_sources', 'position_assignments', 'history_dependencies', 'rule_set', 'versions'] as $group) {
            if (ConversionManifestBuilder::canonical($run->dependency_manifest[$group]) !== ConversionManifestBuilder::canonical($preview->dependencies->groups[$group])) {
                $expected[] = $group;
            }
        }
        $this->assertSame($expected, $result->changed_groups);
        $codes = array_values(array_unique(array_column($preview->issues, 'issue_code')));
        sort($codes, SORT_STRING);
        $this->assertSame($codes, $result->current_issue_codes);
        $this->assertSame($preview->dependency_fingerprint, $result->current_dependency_fingerprint);
        $this->assertSame($preview->snapshot_fingerprint, $result->current_snapshot_fingerprint);
    }

    private function renameLevel(): void
    {
        app(FunctionalMasterService::class)->save($this->actor, 'level', ['code' => $this->level->code,
            'name' => 'Synthetic updated', 'functional_category_id' => $this->level->functional_category_id,
            'sort_order' => 0, 'is_active' => true], $this->level->id);
    }

    public function test_new_annual_changes_candidate_set_without_mutating_prior_quarterly(): void
    {
        DB::table('performance_evaluations')->where('performance_year', 2026)->delete();
        $quarter = $this->evaluation(2026, 'Q3');
        $before = $quarter->fresh()->getRawOriginal();
        $run = $this->save();
        $this->evaluation(2026);
        $result = $this->assess($run);
        $this->assertSame('CHANGED', $result->comparison);
        $this->assertContains('source_candidates', $result->changed_groups);
        $this->assertContains('selected_sources', $result->changed_groups);
        $this->assertSame($before, $quarter->fresh()->getRawOriginal());
    }

    public function test_person_profile_and_mapping_creation_are_nonsemantic(): void
    {
        DB::table('functional_position_levels')->delete();
        $run = $this->save();
        $before = $this->assess($run);
        // Isolate the same profile fields changed by DUK; no import or writer concurrency here.
        DB::table('people')->where('id', $this->case->person_id)->update(['canonical_name' => 'Updated Person', 'normalized_name' => 'UPDATED PERSON']);
        $this->assertSame(get_object_vars($before), get_object_vars($this->assess($run)));
        app(FunctionalMasterService::class)->save($this->actor, 'mapping', [
            'functional_position_id' => $this->history->functional_position_id, 'functional_level_id' => $this->level->id, 'is_active' => true]);
        $this->assertSame(get_object_vars($before), get_object_vars($this->assess($run)));
    }

    public function test_exact_historical_superseded_run_is_not_redirected_to_successor(): void
    {
        $run = $this->save();
        $this->renameLevel();
        $preview = $this->preview();
        $successor = app(ConversionService::class)->replace($this->actor, ConversionReplaceRequest::from([
            'pak_case_id' => $this->case->id, 'expected_predecessor_run_id' => $run->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint, 'revision_reason' => 'Synthetic replacement',
        ]));
        $result = $this->assess($run);
        $this->assertSame($run->id, $result->run_id);
        $this->assertSame(1, $result->revision_number);
        $this->assertSame('SUPERSEDED', $result->observed_run_status);
        $this->assertFalse($result->is_current_active);
        $this->assertSame('CHANGED', $result->comparison);
        $this->assertSame('UNCHANGED', $this->assess($successor)->comparison);
    }

    public static function domainFailures(): array
    {
        return [['missing_rule', 'RULE_SET_NOT_FOUND'], ['status', 'RULE_SET_NOT_USABLE'],
            ['marker', 'RULE_INTEGRITY_ERROR'], ['case_range', 'REQUESTED_RANGE_OUTSIDE_CASE']];
    }

    #[DataProvider('domainFailures')]
    public function test_only_known_noncomparable_domain_outcomes_are_unassessable(string $kind, string $code): void
    {
        $run = $this->save();
        match ($kind) {
            'missing_rule' => DB::table('pak_cases')->where('id', $this->case->id)->update(['pak_rule_set_id' => null]),
            'status' => DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['status' => 'RETIRED']),
            'marker' => DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['first_used_at' => null]),
            'case_range' => DB::table('pak_cases')->where('id', $this->case->id)->update(['period_start' => '2024-01-01']),
        };
        $result = $this->assess($run);
        $this->assertSame('UNASSESSABLE', $result->comparison);
        $this->assertSame($code, $result->failure_code);
        $this->assertNull($result->current_dependency_fingerprint);
        $this->assertNull($result->current_snapshot_fingerprint);
        $this->assertSame([], $result->changed_groups);
        $this->assertSame([], $result->current_issue_codes);
    }

    public function test_snapshot_only_difference_is_changed_without_invented_group(): void
    {
        $run = $this->save();
        DB::table('pak_conversion_runs')->where('id', $run->id)->update(['snapshot_fingerprint' => str_repeat('0', 64)]);
        $result = $this->assess($run);
        $this->assertSame('CHANGED', $result->comparison);
        $this->assertSame([], $result->changed_groups);
        $this->assertSame($run->dependency_fingerprint, $result->current_dependency_fingerprint);
    }

    public static function technicalFailures(): array
    {
        return [['runtime'], ['pdo'], ['query'], ['unknown_rule'], ['unknown_validation']];
    }

    #[DataProvider('technicalFailures')]
    public function test_technical_and_unclassified_errors_escape_unchanged(string $kind): void
    {
        $run = $this->save();
        $error = match ($kind) {
            'runtime' => new \RuntimeException('Controlled infrastructure failure'),
            'pdo' => new \PDOException('Controlled PDO failure'),
            'query' => new QueryException('sqlite', 'select synthetic', [], new \PDOException('Controlled database failure')),
            'unknown_rule' => new ConversionRuleException('UNKNOWN_FUTURE_OUTCOME'),
            'unknown_validation' => ValidationException::withMessages(['other' => 'UNCLASSIFIED']),
        };
        $reader = new class($error) extends ConversionPreviewDependencies
        {
            public function __construct(private \Throwable $error) {}

            public function ruleSet(int $id): ?PakRuleSet
            {
                throw $this->error;
            }
        };
        $this->app->instance(ConversionPreviewDependencies::class, $reader);
        try {
            $this->assess($run);
            $this->fail('Technical error converted to assessment.');
        } catch (\Throwable $actual) {
            $this->assertSame($error, $actual);
        }
    }

    private function metadata(): array
    {
        return ['schema_version' => 1, 'assessment_sequence' => 3, 'comparison' => 'CHANGED',
            'assessment_fingerprint' => str_repeat('a', 64), 'current_dependency_fingerprint' => str_repeat('b', 64),
            'current_snapshot_fingerprint' => str_repeat('c', 64), 'changed_groups' => ['history_dependencies'],
            'current_issue_codes' => [], 'failure_code' => null, 'reason' => 'Synthetic historical review'];
    }

    public function test_supported_review_metadata_sequence_is_read_without_persistence(): void
    {
        $run = $this->save();
        $before = $this->assess($run);
        DB::table('pak_conversion_runs')->where('id', $run->id)->update(['review_assessment' => json_encode($this->metadata()),
            'review_assessed_by' => $this->actor->id, 'review_assessed_at' => '2026-01-02 00:00:00']);
        $result = $this->assess($run);
        $this->assertSame(3, $result->assessment_sequence);
        $this->assertSame('UNCHANGED', $result->comparison);
        $this->assertNotSame($before->assessment_fingerprint, $result->assessment_fingerprint);
    }

    public function test_malformed_metadata_is_rejected_without_reset_or_repair(): void
    {
        $run = $this->save();
        $bad = ['null', '[]', '"text"', '{"assessment_sequence":3}'];
        foreach (['schema_version' => 2, 'assessment_sequence' => '3', 'comparison' => 'APPROVED',
            'assessment_fingerprint' => 'INVALID', 'current_dependency_fingerprint' => null,
            'changed_groups' => ['private'], 'current_issue_codes' => ['private message'], 'failure_code' => 'UNEXPECTED', 'reason' => ' '] as $key => $value) {
            $bad[] = json_encode(array_replace($this->metadata(), [$key => $value]));
        }
        $bad[] = json_encode($this->metadata() + ['manifest' => []]);
        foreach ($bad as $raw) {
            DB::table('pak_conversion_runs')->where('id', $run->id)->update(['review_assessment' => $raw]);
            try {
                $this->assess($run);
                $this->fail('Malformed metadata accepted.');
            } catch (ValidationException $error) {
                $this->assertSame(['review_assessment' => ['REVIEW_ASSESSMENT_INTEGRITY_ERROR']], $error->errors());
            }
        }
    }

    public static function deniedPermissions(): array
    {
        return array_map(fn ($permission) => [$permission], ['pak.access', ...ConversionService::PERMISSIONS, 'inactive']);
    }

    #[DataProvider('deniedPermissions')]
    public function test_all_permissions_and_inactive_actor_reject_before_target_reads(string $permission): void
    {
        $run = $this->save();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        $this->denied = $permission;
        if ($permission === 'inactive') {
            $this->actor->forceFill(['is_active' => false])->save();
        }
        $queries = [];
        try {
            app(ConversionAssessmentService::class)->assess($this->actor, ConversionAssessmentRequest::from(['pak_case_id' => $this->case->id, 'run_id' => $run->id]));
            $this->fail('Unauthorized assessment accepted: '.$permission);
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
            $this->assertStringNotContainsString('pak_conversion_runs', implode(' ', $queries));
            $this->assertStringNotContainsString('pak_cases', implode(' ', $queries));
            $this->clean();
        }

    }

    public function test_wrong_case_run_boundary_has_same_safe_error_as_missing_target(): void
    {
        $run = $this->save();
        $before = $this->state();
        foreach ([['pak_case_id' => $this->case->id + 1, 'run_id' => $run->id],
            ['pak_case_id' => $this->case->id, 'run_id' => $run->id + 100]] as $input) {
            try {
                app(ConversionAssessmentService::class)->assess($this->actor, ConversionAssessmentRequest::from($input));
                $this->fail('Foreign target accepted.');
            } catch (ValidationException $error) {
                $this->assertSame(['run' => ['Invalid conversion assessment target.']], $error->errors());
                $this->clean();
                $this->assertSame($before, $this->state());
            }
        }
    }

    public function test_outer_transaction_is_rejected_without_rolling_back_caller(): void
    {
        $run = $this->save();
        DB::beginTransaction();
        try {
            app(ConversionAssessmentService::class)->assess($this->actor, ConversionAssessmentRequest::from(['pak_case_id' => $this->case->id, 'run_id' => $run->id]));
            $this->fail('Outer transaction accepted.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('top-level', $error->getMessage());
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
        } finally {
            DB::rollBack();
        }
        $this->clean();
    }
}
