<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionAssessmentRequest;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionReplaceRequest;
use App\Domain\Pak\DTOs\ConversionReviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\Services\ConversionAssessmentService;
use App\Domain\Pak\Services\ConversionReviewService;
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
use App\Models\PakEvent;
use App\Models\PakRuleSet;
use App\Models\PerformanceEvaluation;
use App\Models\PerformancePredicateRule;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
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

class ConversionReviewLifecycleTest extends TestCase
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

    private function assessment(PakConversionRun $run)
    {
        return app(ConversionAssessmentService::class)->assess($this->actor,
            ConversionAssessmentRequest::from(['pak_case_id' => $this->case->id, 'run_id' => $run->id]));
    }

    private function reviewRequest(PakConversionRun $run, array $overrides = []): ConversionReviewRequest
    {
        $assessment = $this->assessment($run);

        return ConversionReviewRequest::from(array_replace([
            'pak_case_id' => $this->case->id, 'run_id' => $run->id,
            'expected_run_status' => $assessment->observed_run_status,
            'expected_case_lock_version' => $assessment->observed_case_lock_version,
            'expected_assessment_sequence' => $assessment->assessment_sequence,
            'expected_assessment_fingerprint' => $assessment->assessment_fingerprint,
            'reason' => '  Synthetic review reason  ',
        ], $overrides));
    }

    private function renameLevel(string $name = 'Synthetic changed label'): void
    {
        app(FunctionalMasterService::class)->save($this->actor, 'level', ['code' => $this->level->code,
            'name' => $name, 'functional_category_id' => $this->level->functional_category_id,
            'sort_order' => 0, 'is_active' => true], $this->level->id);
    }

    private function successfulReview(PakConversionRun $run): PakConversionRun
    {
        $assessment = $this->assessment($run);
        $request = $this->reviewRequest($run);
        $before = $this->state();
        $attempts = 0;
        $watch = true;
        DB::connection()->beforeStartingTransaction(function () use (&$attempts, &$watch) {
            if ($watch) {
                $attempts++;
            }
        });
        try {
            $result = app(ConversionReviewService::class)->assessReview($this->actor, $request);
        } finally {
            $watch = false;
        }
        $this->assertSame(1, $attempts);
        $this->clean();
        $after = $this->state();
        foreach ($before as $table => $rows) {
            if (! in_array($table, ['pak_conversion_runs', 'pak_events'], true)) {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
        $allowed = array_flip(['status', 'review_assessment', 'review_assessed_by', 'review_assessed_at', 'updated_at']);
        foreach ($before['pak_conversion_runs'] as $index => $row) {
            $this->assertSame(array_diff_key($row, $allowed), array_diff_key($after['pak_conversion_runs'][$index], $allowed));
        }
        $this->assertSame($run->id, $result->id);
        $this->assertSame($result->fresh()->getRawOriginal(), $result->getRawOriginal());
        $this->assertSame('REQUIRES_REVIEW', $result->status->value);
        $this->assertSame($this->actor->id, $result->review_assessed_by);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $result->review_assessed_at->format('Y-m-d H:i:s'));
        $this->assertSame([
            'schema_version' => 1, 'assessment_sequence' => $assessment->assessment_sequence + 1,
            'comparison' => $assessment->comparison, 'assessment_fingerprint' => $assessment->assessment_fingerprint,
            'current_dependency_fingerprint' => $assessment->current_dependency_fingerprint,
            'current_snapshot_fingerprint' => $assessment->current_snapshot_fingerprint,
            'changed_groups' => $assessment->changed_groups, 'current_issue_codes' => $assessment->current_issue_codes,
            'failure_code' => $assessment->failure_code, 'reason' => 'Synthetic review reason',
        ], $result->review_assessment);
        $this->assertCount(count($before['pak_events']) + 1, $after['pak_events']);
        $this->assertSame($before['pak_events'], array_slice($after['pak_events'], 0, count($before['pak_events'])));
        $event = PakEvent::orderByDesc('id')->firstOrFail();
        $this->assertSame('CONVERSION_RUN_REVIEW_ASSESSED', $event->event_code);
        $this->assertSame('PakConversionRun', $event->subject_type);
        $this->assertSame($run->id, $event->subject_id);
        $this->assertSame($this->case->id, $event->pak_case_id);
        $this->assertSame($this->actor->id, $event->actor_id);
        $prior = ['case_id' => $this->case->id, 'run_id' => $run->id, 'revision_number' => $run->revision_number,
            'status' => $assessment->observed_run_status, 'assessment_sequence' => $assessment->assessment_sequence];
        if ($run->fresh()->review_assessment !== null && $assessment->assessment_sequence > 0) {
            $prior['comparison'] = json_decode($before['pak_conversion_runs'][0]['review_assessment'], true)['comparison'];
        }
        $summary = ['case_id' => $this->case->id, 'run_id' => $run->id, 'revision_number' => $run->revision_number,
            'status' => 'REQUIRES_REVIEW', 'assessment_sequence' => $assessment->assessment_sequence + 1,
            'comparison' => $assessment->comparison, 'changed_groups' => $assessment->changed_groups,
            'current_issue_codes' => $assessment->current_issue_codes];
        if ($assessment->failure_code !== null) {
            $summary['failure_code'] = $assessment->failure_code;
        }
        $this->assertSame(['before' => $prior, 'after' => $summary, 'reason' => 'Synthetic review reason'], $event->payload);
        $this->assertNotSame($assessment->assessment_fingerprint, $this->assessment($result)->assessment_fingerprint);

        return $result;
    }

    private function rejected(ConversionReviewRequest $request, string $field): void
    {
        $before = $this->state();
        try {
            app(ConversionReviewService::class)->assessReview($this->actor, $request);
            $this->fail('Invalid review accepted.');
        } catch (ValidationException $error) {
            $this->assertSame([$field], array_keys($error->errors()));
        }
        $this->clean();
        $this->assertSame($before, $this->state());
    }

    public function test_preview_changed_review_preserves_all_calculation_evidence_and_case(): void
    {
        $run = $this->save();
        $this->renameLevel();
        $this->freezeTime();
        $this->successfulReview($run);
    }

    public static function domainStates(): array
    {
        return [['marker'], ['missing_rule'], ['retired'], ['range']];
    }

    #[DataProvider('domainStates')]
    public function test_preview_domain_unassessable_is_reviewable_without_bypassing_coordination(string $kind): void
    {
        $run = $this->save();
        match ($kind) {
            'marker' => DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['first_used_at' => null]),
            'missing_rule' => DB::table('pak_cases')->where('id', $this->case->id)->update(['pak_rule_set_id' => null]),
            'retired' => DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['status' => 'RETIRED']),
            'range' => DB::table('pak_cases')->where('id', $this->case->id)->update(['period_start' => '2024-01-01']),
        };
        $this->assertSame('UNASSESSABLE', $this->assessment($run)->comparison);
        $this->freezeTime();
        $this->successfulReview($run);
    }

    public function test_preview_unchanged_rejects_reason_only_mutation(): void
    {
        $run = $this->save();
        $this->rejected($this->reviewRequest($run), 'review');
    }

    public static function reviewStates(): array
    {
        return [['UNCHANGED'], ['CHANGED'], ['UNASSESSABLE']];
    }

    #[DataProvider('reviewStates')]
    public function test_requires_review_assessment_update_increments_sequence_without_clearing_original_issues(string $comparison): void
    {
        $run = $this->save($this->request('2023-11-02'));
        $this->freezeTime();
        $run = $this->successfulReview($run);
        if ($comparison === 'CHANGED') {
            // Partial-month diagnostic runs need not read master evidence; change relevant case context.
            DB::table('pak_cases')->where('id', $this->case->id)->update(['purpose_type' => 'KJ']);
        }
        if ($comparison === 'UNASSESSABLE') {
            DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['first_used_at' => null]);
        }
        $this->assertSame($comparison, $this->assessment($run)->comparison);
        $result = $this->successfulReview($run);
        $this->assertSame(2, $result->review_assessment['assessment_sequence']);
        $this->assertNotEmpty($result->original_issues);
    }

    public static function staleFields(): array
    {
        return [['expected_run_status', 'REQUIRES_REVIEW'], ['expected_case_lock_version', 0],
            ['expected_assessment_sequence', 1], ['expected_assessment_fingerprint', str_repeat('0', 64)]];
    }

    #[DataProvider('staleFields')]
    public function test_each_stale_precondition_fails_without_mutation(string $field, mixed $value): void
    {
        $run = $this->save();
        $this->renameLevel();
        $this->rejected($this->reviewRequest($run, [$field => $value]), $field === 'expected_case_lock_version' ? 'case' : $field);
    }

    public function test_dependency_change_after_read_assessment_rejects_frozen_token(): void
    {
        $run = $this->save();
        $this->renameLevel();
        $request = $this->reviewRequest($run);
        $this->renameLevel('Second change before mutation');
        $this->rejected($request, 'expected_assessment_fingerprint');
    }

    public function test_reusing_same_sequence_after_success_is_not_idempotent_success(): void
    {
        $run = $this->save($this->request('2023-11-02'));
        $request = $this->reviewRequest($run);
        $this->successfulReview($run);
        $this->rejected($request, 'expected_assessment_sequence');
    }

    public function test_malformed_historical_metadata_is_rejected_not_repaired(): void
    {
        $run = $this->save();
        $this->renameLevel();
        $request = $this->reviewRequest($run);
        DB::table('pak_conversion_runs')->where('id', $run->id)->update(['review_assessment' => '{"schema_version":99,"assessment_sequence":0}']);
        $this->rejected($request, 'review_assessment');
    }

    public static function caseBoundaries(): array
    {
        return [['status', 'FINAL'], ['finalized_at', '2026-01-01 00:00:00']];
    }

    #[DataProvider('caseBoundaries')]
    public function test_case_must_be_draft_and_not_finalized(string $field, string $value): void
    {
        $run = $this->save();
        $this->renameLevel();
        $request = $this->reviewRequest($run);
        DB::table('pak_cases')->where('id', $this->case->id)->update([$field => $value]);
        $this->rejected($request, 'case');
    }

    public function test_wrong_case_and_missing_run_reject_without_foreign_evidence(): void
    {
        $run = $this->save();
        $this->renameLevel();
        $foreign = $this->case->replicate();
        $foreign->save();
        $request = $this->reviewRequest($run);
        $this->rejected(ConversionReviewRequest::from(array_replace(get_object_vars($request), [
            'pak_case_id' => $foreign->id, 'expected_case_lock_version' => $foreign->lock_version,
        ])), 'run');
        $this->rejected(ConversionReviewRequest::from(array_replace(get_object_vars($request), ['run_id' => $run->id + 100])), 'run');
    }

    public static function deniedPermissions(): array
    {
        return array_map(fn ($permission) => [$permission], ['pak.access', ...ConversionService::PERMISSIONS, 'inactive']);
    }

    #[DataProvider('deniedPermissions')]
    public function test_authorization_rejects_before_discovery_or_transaction(string $permission): void
    {
        $run = $this->save();
        $request = $this->reviewRequest($run);
        $this->denied = $permission;
        if ($permission === 'inactive') {
            $this->actor->forceFill(['is_active' => false])->save();
        }
        $queries = [];
        $attempts = 0;
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        DB::connection()->beforeStartingTransaction(function () use (&$attempts) {
            $attempts++;
        });
        try {
            app(ConversionReviewService::class)->assessReview($this->actor, $request);
            $this->fail('Unauthorized review accepted.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
            $this->assertSame(0, $attempts);
            $this->assertStringNotContainsString('pak_cases', implode(' ', $queries));
            $this->assertStringNotContainsString('pak_conversion_runs', implode(' ', $queries));
            $this->clean();
        }
    }

    public function test_caller_transaction_is_rejected_without_consuming_caller_state(): void
    {
        $run = $this->save();
        $request = $this->reviewRequest($run);
        DB::beginTransaction();
        try {
            app(ConversionReviewService::class)->assessReview($this->actor, $request);
            $this->fail('Caller transaction accepted.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('top-level', $error->getMessage());
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
        } finally {
            DB::rollBack();
        }
        $this->clean();
    }

    private function replaceRequest(PakConversionRun $run): ConversionReplaceRequest
    {
        $preview = $this->preview();

        return ConversionReplaceRequest::from(['pak_case_id' => $this->case->id, 'expected_predecessor_run_id' => $run->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint, 'revision_reason' => 'Synthetic replacement']);
    }

    public function test_review_first_does_not_invalidate_frozen_replacement_request(): void
    {
        $run = $this->save();
        $this->renameLevel();
        $replace = $this->replaceRequest($run);
        $this->successfulReview($run);
        $successor = app(ConversionService::class)->replace($this->actor, $replace);
        $this->assertSame($run->id, $successor->predecessor_run_id);
        $this->assertSame('SUPERSEDED', $run->fresh()->status->value);
        $this->assertSame($replace->expected_case_lock_version + 1, $this->case->fresh()->lock_version);
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $this->clean();
    }

    public function test_replacement_first_rejects_exact_superseded_target_never_retargets(): void
    {
        $run = $this->save();
        $this->renameLevel();
        $review = $this->reviewRequest($run);
        app(ConversionService::class)->replace($this->actor, $this->replaceRequest($run));
        $this->rejected($review, 'case');
        $this->rejected(ConversionReviewRequest::from(array_replace(get_object_vars($review),
            ['expected_case_lock_version' => $this->case->fresh()->lock_version])), 'run');
    }

    public static function faults(): array
    {
        return [['after_update'], ['audit'], ['deadlock'], ['database'], ['before_commit'], ['after_commit'], ['pdo_committed']];
    }

    #[DataProvider('faults')]
    public function test_fault_cleanup_single_attempt_and_commit_uncertainty(string $stage): void
    {
        $run = $this->save();
        $this->renameLevel();
        $request = $this->reviewRequest($run);
        $before = $this->state();
        $pdoError = new \PDOException('Synthetic database fault');
        $pdoError->errorInfo = $stage === 'deadlock' ? ['40001', 1213, 'Synthetic deadlock'] : ['HY000', 1205, 'Synthetic timeout'];
        $error = in_array($stage, ['deadlock', 'database'], true)
            ? new QueryException('sqlite', 'synthetic', [], $pdoError) : new \RuntimeException('Synthetic review failure');
        $attempts = $callbacks = $hits = 0;
        $watch = true;
        DB::connection()->beforeStartingTransaction(function () use (&$attempts) {
            $attempts++;
        });
        DB::listen(function ($q) use ($stage, $error, &$watch, &$callbacks, &$hits) {
            if (! $watch) {
                return;
            }
            if (str_starts_with($q->sql, 'update "pak_conversion_runs"')) {
                DB::afterCommit(function () use (&$callbacks) {
                    $callbacks++;
                });
                if (in_array($stage, ['after_update', 'deadlock', 'database'], true)) {
                    $hits++;
                    throw $error;
                }
            }
            if ($stage === 'audit' && str_starts_with($q->sql, 'insert into "pak_events"')) {
                $hits++;
                throw $error;
            }
        });
        $dispatcher = clone DB::connection()->getEventDispatcher();
        $originalDispatcher = DB::connection()->getEventDispatcher();
        DB::connection()->setEventDispatcher($dispatcher);
        $event = $stage === 'after_commit' ? TransactionCommitted::class : TransactionCommitting::class;
        if (in_array($stage, ['before_commit', 'after_commit', 'pdo_committed'], true)) {
            $dispatcher->listen($event, function () use ($stage, $error, &$hits) {
                $hits++;
                if ($stage === 'pdo_committed') {
                    DB::connection()->getPdo()->commit();
                }
                throw $error;
            });
        }
        try {
            app(ConversionReviewService::class)->assessReview($this->actor, $request);
            $this->fail('Fault swallowed.');
        } catch (\Throwable $actual) {
            $this->assertSame($error, $actual);
        } finally {
            $watch = false;
            DB::connection()->setEventDispatcher($originalDispatcher);
        }
        $this->assertSame(1, $attempts);
        $this->assertSame(1, $hits);
        $this->assertSame($stage === 'after_commit' ? 1 : 0, $callbacks);
        $this->clean();
        if (in_array($stage, ['after_commit', 'pdo_committed'], true)) {
            $this->assertSame('REQUIRES_REVIEW', $run->fresh()->status->value);
            $this->assertSame(1, $run->fresh()->review_assessment['assessment_sequence']);
            $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->count());
        } else {
            $this->assertSame($before, $this->state());
        }
        // A later unrelated transaction cannot fire a rolled-back review callback.
        DB::transaction(fn () => null);
        $this->assertSame($stage === 'after_commit' ? 1 : 0, $callbacks);
        $this->clean();
    }

    public function test_zero_affected_rows_fails_closed_without_audit(): void
    {
        $run = $this->save();
        $this->renameLevel();
        $request = $this->reviewRequest($run);
        DB::unprepared('CREATE TEMP TRIGGER ignore_review BEFORE UPDATE ON pak_conversion_runs BEGIN SELECT RAISE(IGNORE); END');
        try {
            $this->rejected($request, 'run');
        } finally {
            DB::unprepared('DROP TRIGGER ignore_review');
        }
    }

    public function test_canonical_lock_intent_and_scalar_discovery_precede_authoritative_rebuild(): void
    {
        $run = $this->save();
        $this->renameLevel();
        $request = $this->reviewRequest($run);
        $connection = DB::connection();
        $original = $connection->getQueryGrammar();
        $grammar = new class($connection) extends SQLiteGrammar
        {
            public array $reads = [];

            public function compileSelect(Builder $query)
            {
                $this->reads[] = ['table' => $query->from, 'lock' => $query->lock, 'level' => DB::transactionLevel()];

                return parent::compileSelect($query);
            }
        };
        $connection->setQueryGrammar($grammar);
        try {
            app(ConversionReviewService::class)->assessReview($this->actor, $request);
        } finally {
            $connection->setQueryGrammar($original);
        }
        $locked = array_values(array_filter($grammar->reads, fn ($read) => $read['lock'] !== null));
        $tables = array_column($locked, 'table');
        $order = ['pak_cases', 'people', 'pak_rule_sets', 'performance_predicate_rules', 'ak_coefficient_rules',
            'functional_position_levels', 'functional_positions', 'functional_levels', 'functional_categories',
            'pak_conversion_runs', 'performance_evaluations', 'employee_position_histories'];
        $last = -1;
        foreach ($order as $table) {
            $index = array_search($table, $tables, true);
            $this->assertNotFalse($index, $table);
            $this->assertGreaterThan($last, $index, $table);
            $this->assertSame(1, $locked[$index]['level']);
            $last = $index;
        }
        $historyIndex = array_search('employee_position_histories', $tables, true);
        $this->assertNotContains('functional_position_levels', array_slice($tables, $historyIndex));
        $outside = array_values(array_filter($grammar->reads, fn ($read) => $read['level'] === 0));
        $this->assertContains('employee_position_histories', array_column($outside, 'table'));
        $this->assertContains('functional_position_levels', array_column($outside, 'table'));
        foreach ($outside as $read) {
            $this->assertNull($read['lock']);
        }
        $this->clean();
    }

    public function test_audit_failure_restores_existing_review_metadata_and_sequence(): void
    {
        $run = $this->save($this->request('2023-11-02'));
        $run = $this->successfulReview($run);
        $request = $this->reviewRequest($run);
        $before = $this->state();
        $error = new \RuntimeException('Synthetic audit insertion failure');
        $watch = true;
        $attempts = $callbacks = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$attempts) {
            $attempts++;
        });
        DB::connection()->beforeExecuting(function ($sql) use (&$watch, $error, &$callbacks) {
            if ($watch && str_starts_with($sql, 'insert into "pak_events"')) {
                DB::afterCommit(function () use (&$callbacks) {
                    $callbacks++;
                });
                throw $error;
            }
        });
        try {
            app(ConversionReviewService::class)->assessReview($this->actor, $request);
            $this->fail('Audit error swallowed.');
        } catch (\Throwable $actual) {
            $this->assertSame($error, $actual);
        } finally {
            $watch = false;
        }
        $this->assertSame(1, $attempts);
        $this->assertSame(0, $callbacks);
        $this->assertSame($before, $this->state());
        $this->clean();
    }

    public function test_mapping_plan_change_fails_closed_even_when_semantic_assessment_token_is_unchanged(): void
    {
        DB::table('functional_position_levels')->delete();
        $run = $this->save();
        $this->renameLevel();
        $request = $this->reviewRequest($run);
        $watch = true;
        $before = null;
        DB::connection()->beforeStartingTransaction(function () use (&$watch, &$before) {
            if (! $watch) {
                return;
            }
            $watch = false;
            // Deterministic single-process interleaving after scalar discovery, before owned begin.
            app(FunctionalMasterService::class)->save($this->actor, 'mapping', [
                'functional_position_id' => $this->history->functional_position_id,
                'functional_level_id' => $this->level->id, 'is_active' => true]);
            $before = $this->state();
        });
        try {
            app(ConversionReviewService::class)->assessReview($this->actor, $request);
            $this->fail('Obsolete mapping plan accepted.');
        } catch (ValidationException $error) {
            $this->assertSame(['snapshot' => ['Dependency lock plan changed. Obtain a new preview.']], $error->errors());
        }
        $this->assertNotNull($before);
        $this->assertSame($before, $this->state());
        $this->assertSame($request->expected_assessment_fingerprint, $this->assessment($run)->assessment_fingerprint);
        $this->clean();
    }

    public function test_profile_and_mapping_changes_before_discovery_do_not_invalidate_review_intent(): void
    {
        DB::table('functional_position_levels')->delete();
        $run = $this->save();
        $this->renameLevel();
        $request = $this->reviewRequest($run);
        DB::table('people')->where('id', $this->case->person_id)->update(['canonical_name' => 'Updated Person', 'normalized_name' => 'UPDATED PERSON']);
        app(FunctionalMasterService::class)->save($this->actor, 'mapping', [
            'functional_position_id' => $this->history->functional_position_id,
            'functional_level_id' => $this->level->id, 'is_active' => true]);
        $result = app(ConversionReviewService::class)->assessReview($this->actor, $request);
        $this->assertSame($request->expected_assessment_fingerprint, $result->review_assessment['assessment_fingerprint']);
        $this->assertSame('REQUIRES_REVIEW', $result->status->value);
        $this->assertSame($request->expected_case_lock_version, $this->case->fresh()->lock_version);
        $this->clean();
    }
}
