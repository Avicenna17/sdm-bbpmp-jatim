<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionReplaceRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\Services\ConversionManifestBuilder;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\EmployeeHistoryService;
use App\Domain\Pak\Services\FunctionalMasterService;
use App\Models\AkCoefficientRule;
use App\Models\EmployeePositionHistory;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use App\Models\PakCase;
use App\Models\PakConversionPeriod;
use App\Models\PakConversionRun;
use App\Models\PakEvent;
use App\Models\PakRuleSet;
use App\Models\PerformanceEvaluation;
use App\Models\PerformancePredicateRule;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
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

class ConversionReplacementSaveTest extends TestCase
{
    private User $actor;

    private PakCase $case;

    private PakRuleSet $rule;

    private EmployeePositionHistory $history;

    private FunctionalLevel $level;

    private ?string $denied = null;

    private PakConversionRun $predecessor;

    private FunctionalLevel $correctedLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
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
        $this->correctedLevel = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'CORRECTED', 'name' => 'Synthetic corrected level']);
        FunctionalPositionLevel::create(['functional_position_id' => $position->id, 'functional_level_id' => $this->correctedLevel->id]);
        AkCoefficientRule::create(['pak_rule_set_id' => $this->rule->id, 'functional_level_id' => $this->correctedLevel->id, 'annual_coefficient' => '37.5']);
        // Isolated fixture stands in for accepted case first-use lifecycle, never production preview behavior.
        DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['first_used_at' => '2026-01-01 00:00:00']);
        $this->case = PakCase::create(['person_id' => $person->id, 'pak_rule_set_id' => $this->rule->id, 'purpose_type' => 'KP', 'period_start' => '2023-01-01', 'period_end' => '2026-12-31', 'employee_data_as_of' => '2026-01-01', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $this->history = EmployeePositionHistory::create(['person_id' => $person->id, 'position_name' => 'Synthetic position', 'position_type' => 'JF', 'functional_position_id' => $position->id, 'functional_level_id' => $this->level->id,
            'tmt_start' => '2023-01-01', 'sk_number' => 'SYNTHETIC-SK-001', 'sk_date' => '2022-12-15', 'source' => 'FIXTURE', 'source_ref' => 'Synthetic documentary reference', 'verified_by' => $this->actor->id, 'verified_at' => now(), 'created_by' => $this->actor->id]);
        $this->case->refresh();
        foreach ([2023, 2024, 2025, 2026] as $year) {
            $this->evaluation($year);
        }
        $this->predecessor = $this->save();
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

    private function replacementRequest(?PakConversionRun $target = null): ConversionReplaceRequest
    {
        $preview = $this->preview();

        return ConversionReplaceRequest::from(['pak_case_id' => $this->case->id,
            'expected_predecessor_run_id' => ($target ?? $this->predecessor)->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint, 'revision_reason' => '  Synthetic explicit replacement  ']);
    }

    private function correctPosition(?string $skNumber = 'SYNTHETIC-CORRECTED-SK'): EmployeePositionHistory
    {
        return app(EmployeeHistoryService::class)->record($this->actor, $this->case->person, [
            'position_name' => 'Synthetic corrected position', 'position_type' => 'JF',
            'functional_position_id' => $this->history->functional_position_id,
            'functional_level_id' => $this->correctedLevel->id, 'tmt_start' => '2023-01-01',
            'sk_number' => $skNumber, 'sk_date' => '2022-12-20', 'source_ref' => 'Synthetic corrected evidence',
        ], true, $this->history->id, 'Synthetic position correction');
    }

    private function renameLevel(string $name): void
    {
        app(FunctionalMasterService::class)->save($this->actor, 'level', [
            'code' => $this->level->code, 'name' => $name, 'functional_category_id' => $this->level->functional_category_id,
            'sort_order' => $this->level->fresh()->sort_order, 'is_active' => true,
        ], $this->level->id);
    }

    private function state(): array
    {
        return ['runs' => DB::table('pak_conversion_runs')->orderBy('id')->get()->toArray(),
            'periods' => DB::table('pak_conversion_periods')->orderBy('id')->get()->toArray(),
            'case' => $this->case->fresh()->getRawOriginal(),
            'events' => DB::table('pak_events')->orderBy('id')->get()->toArray(),
            'first_use' => $this->rule->fresh()->getRawOriginal('first_used_at')];
    }

    private function assertClean(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertCount(0, app('db.transactions')->getPendingTransactions());
        $this->assertCount(0, app('db.transactions')->getCommittedTransactions());
    }

    private function assertUnchanged(array $before): void
    {
        $this->assertEquals($before, $this->state());
        $this->assertClean();
    }

    public function test_replacement_rebuilds_new_exact_results_and_preserves_all_old_evidence(): void
    {
        // Synthetic review metadata is historical evidence; successor must not inherit it.
        DB::table('pak_conversion_runs')->where('id', $this->predecessor->id)->update([
            'review_assessment' => json_encode(['note' => 'Synthetic historical assessment']),
            'review_assessed_by' => $this->actor->id, 'review_assessed_at' => '2026-01-02 00:00:00',
        ]);
        $old = $this->predecessor->fresh()->getRawOriginal();
        $oldPeriods = $this->predecessor->periods()->get()->map->getRawOriginal()->all();
        $history = $this->correctPosition();
        $preview = $this->preview();
        $request = $this->replacementRequest();
        $firstUse = $this->rule->fresh()->getRawOriginal('first_used_at');
        $new = app(ConversionService::class)->replace($this->actor, $request);

        $this->assertSame('75/1', $old['total_numerator'].'/'.$old['total_denominator']);
        $this->assertSame('225/2', $new->total_numerator.'/'.$new->total_denominator);
        $this->assertSame(['25/4', '75/2', '75/2', '125/4'], $new->periods->map(fn ($p) => $p->result_numerator.'/'.$p->result_denominator)->all());
        $this->assertNotSame($old['dependency_fingerprint'], $new->dependency_fingerprint);
        $this->assertNotSame($old['snapshot_fingerprint'], $new->snapshot_fingerprint);
        $this->assertSame($preview->dependencies->groups, $new->dependency_manifest);
        $this->assertSame(ConversionManifestBuilder::hash($new->dependency_manifest), $new->dependency_fingerprint);
        $this->assertSame($request->expected_dependency_fingerprint, $new->dependency_fingerprint);
        $this->assertSame($request->expected_snapshot_fingerprint, $new->snapshot_fingerprint);
        $this->assertSame(2, $new->revision_number);
        $this->assertSame($this->predecessor->id, $new->predecessor_run_id);
        $this->assertSame('Synthetic explicit replacement', $new->revision_reason);
        $this->assertSame('PREVIEW', $new->status->value);
        $this->assertSame($this->case->id, $new->active_case_id);
        $this->assertSame($this->case->person_id, $new->person_id);
        $this->assertSame($this->rule->id, $new->pak_rule_set_id);
        $this->assertSame($request->expected_case_lock_version + 1, $new->case_lock_version);
        $this->assertSame($new->case_lock_version, $this->case->fresh()->lock_version);
        foreach (['review_assessment', 'review_assessed_by', 'review_assessed_at', 'superseded_at', 'superseded_by_user_id'] as $field) {
            $this->assertNull($new->$field);
        }
        foreach ($new->periods as $period) {
            $this->assertSame($history->id, $period->employee_position_history_id);
            $this->assertSame($history->id, $period->position_snapshot['history_id']);
            $this->assertSame($this->correctedLevel->id, $period->functional_level_id);
            $this->assertSame('37.500000', $period->annual_coefficient);
            $this->assertNotContains($period->id, array_column($oldPeriods, 'id'));
        }
        $after = $this->predecessor->fresh()->getRawOriginal();
        $lifecycle = array_flip(['status', 'active_case_id', 'superseded_by_user_id', 'superseded_at', 'updated_at']);
        $this->assertSame(array_diff_key($old, $lifecycle), array_diff_key($after, $lifecycle));
        $this->assertSame('SUPERSEDED', $after['status']);
        $this->assertNull($after['active_case_id']);
        $this->assertSame($this->actor->id, $after['superseded_by_user_id']);
        $this->assertNotNull($after['superseded_at']);
        $this->assertSame($oldPeriods, $this->predecessor->periods()->get()->map->getRawOriginal()->all());
        $this->assertSame($firstUse, $this->rule->fresh()->getRawOriginal('first_used_at'));
        $this->assertSame(1, PakConversionRun::where('active_case_id', $this->case->id)->count());
        $this->assertSame(2, PakConversionRun::count());
        $this->assertSame(8, PakConversionPeriod::count());
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $event = PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->sole();
        $this->assertSame('PakConversionRun', $event->subject_type);
        $this->assertSame($new->id, $event->subject_id);
        $this->assertSame($this->case->id, $event->pak_case_id);
        $this->assertSame(['before', 'after', 'reason', 'supersedes_id'], array_keys($event->payload));
        $this->assertSame(['run_id', 'revision_number', 'status'], array_keys($event->payload['before']));
        $this->assertSame(['case_id', 'run_id', 'revision_number', 'status', 'issue_codes'], array_keys($event->payload['after']));
        $this->assertSame('PREVIEW', $event->payload['before']['status']);
        $this->assertSame($this->predecessor->id, $event->payload['supersedes_id']);
        $this->assertSame($request->revision_reason, $event->payload['reason']);
        $this->assertClean();
    }

    public function test_diagnostic_successor_uses_existing_saveability_without_fabricated_values(): void
    {
        $this->correctPosition(null);
        $new = app(ConversionService::class)->replace($this->actor, $this->replacementRequest());
        $this->assertSame('REQUIRES_REVIEW', $new->status->value);
        $this->assertNull($new->total_numerator);
        $this->assertNull($new->total_denominator);
        $this->assertSame(['REGIME_EVIDENCE_REQUIRES_REVIEW'], array_values(array_unique(array_column($new->original_issues, 'issue_code'))));
        // Existing diagnostic orchestration retains one unresolved line per month.
        $this->assertCount(36, $new->periods);
        foreach ($new->periods as $period) {
            $this->assertNull($period->result_numerator);
            $this->assertNull($period->result_denominator);
            $this->assertNull($period->rule_snapshot);
            $this->assertNull($period->ak_coefficient_rule_id);
        }
        $this->assertSame('SUPERSEDED', $this->predecessor->fresh()->status->value);
        $this->assertSame($this->case->id, $new->active_case_id);
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $this->assertClean();
    }

    public function test_two_explicit_changes_form_revision_one_two_three_without_branching(): void
    {
        $this->renameLevel('Updated once');
        $two = app(ConversionService::class)->replace($this->actor, $this->replacementRequest());
        $this->renameLevel('Updated twice');
        $three = app(ConversionService::class)->replace($this->actor, $this->replacementRequest($two));
        $this->assertSame([1, 2, 3], PakConversionRun::orderBy('revision_number')->pluck('revision_number')->all());
        $this->assertSame($this->predecessor->id, $two->predecessor_run_id);
        $this->assertSame($two->id, $three->predecessor_run_id);
        $this->assertSame(['SUPERSEDED', 'SUPERSEDED', 'PREVIEW'], PakConversionRun::orderBy('revision_number')->get()->map(fn ($r) => $r->status->value)->all());
        $this->assertSame($three->id, PakConversionRun::where('active_case_id', $this->case->id)->sole()->id);
        $this->assertSame($this->predecessor->case_lock_version + 2, $this->case->fresh()->lock_version);
        $this->assertSame(2, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $before = $this->state();
        try {
            $this->save();
            $this->fail('Initial save accepted history.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('case', $error->errors());
        }
        $this->assertUnchanged($before);
    }

    public function test_reason_only_no_change_rejects_without_a_revision(): void
    {
        $request = $this->replacementRequest();
        $before = $this->state();
        try {
            app(ConversionService::class)->replace($this->actor, $request);
            $this->fail('No-change replacement accepted.');
        } catch (ValidationException $error) {
            $this->assertSame(['replacement'], array_keys($error->errors()));
        }
        $this->assertUnchanged($before);
    }

    public static function oneHashChanges(): array
    {
        return [['dependency_fingerprint'], ['snapshot_fingerprint']];
    }

    #[DataProvider('oneHashChanges')]
    public function test_no_change_requires_both_hashes_to_match(string $field): void
    {
        // Structural historical-hash fixture isolates the AND policy; normal rebuild proof
        // uses public history correction above and changes real calculation operands.
        DB::table('pak_conversion_runs')->where('id', $this->predecessor->id)->update([$field => str_repeat('f', 64)]);
        $new = app(ConversionService::class)->replace($this->actor, $this->replacementRequest());
        $this->assertSame(2, $new->revision_number);
        $this->assertSame('75', $new->total_numerator);
        $this->assertClean();
    }

    public static function staleInputs(): array
    {
        return [['expected_case_lock_version', 'case'], ['expected_dependency_fingerprint', 'snapshot'],
            ['expected_snapshot_fingerprint', 'snapshot'], ['changed_dependency', 'snapshot'],
            ['expected_predecessor_run_id', 'expected_predecessor_run_id'], ['requested_start', 'requested_start']];
    }

    #[DataProvider('staleInputs')]
    public function test_stale_preconditions_fail_before_lifecycle_mutation(string $field, string $errorField): void
    {
        $input = get_object_vars($this->replacementRequest());
        if ($field === 'changed_dependency') {
            $this->renameLevel('Changed after preview');
        } else {
            $input[$field] = match ($field) {
                'expected_case_lock_version' => 0,
                'expected_predecessor_run_id' => 999999,
                'requested_start' => '2023-12-01',
                default => str_repeat('e', 64),
            };
        }
        $before = $this->state();
        try {
            app(ConversionService::class)->replace($this->actor, ConversionReplaceRequest::from($input));
            $this->fail('Stale request accepted.');
        } catch (ValidationException $error) {
            $this->assertSame([$errorField], array_keys($error->errors()));
        }
        $this->assertUnchanged($before);
    }

    public static function permissions(): array
    {
        return array_map(fn ($p) => [$p], ['inactive', 'pak.access', ...ConversionService::PERMISSIONS]);
    }

    #[DataProvider('permissions')]
    public function test_authorization_rejects_before_discovery_and_transaction(string $permission): void
    {
        $request = $this->replacementRequest();
        if ($permission === 'inactive') {
            $this->actor->forceFill(['is_active' => false])->save();
        } else {
            $this->denied = $permission;
        }
        $before = $this->state();
        $attempts = 0;
        $discovery = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$attempts) {
            $attempts++;
        });
        DB::listen(function ($q) use (&$discovery) {
            if (str_contains($q->sql, 'from "employee_position_histories"')) {
                $discovery++;
            }
        });
        try {
            app(ConversionService::class)->replace($this->actor, $request);
            $this->fail('Unauthorized replacement accepted.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(0, $attempts);
        $this->assertSame(0, $discovery);
        $this->assertUnchanged($before);
    }

    public function test_caller_transaction_is_rejected_without_rolling_back_caller(): void
    {
        $request = $this->replacementRequest();
        $before = $this->state();
        DB::beginTransaction();
        try {
            app(ConversionService::class)->replace($this->actor, $request);
            $this->fail('Caller transaction accepted.');
        } catch (\LogicException $error) {
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
        } finally {
            DB::rollBack();
        }
        $this->assertUnchanged($before);
    }

    public static function faults(): array
    {
        return [['supersession'], ['run'], ['period'], ['case'], ['audit']];
    }

    #[DataProvider('faults')]
    public function test_fault_after_executed_write_rolls_back_entire_replacement(string $stage): void
    {
        $this->renameLevel('Fault fixture');
        $request = $this->replacementRequest();
        $before = $this->state();
        $attempts = $hits = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$attempts) {
            $attempts++;
        });
        [$operation, $table] = match ($stage) {
            'supersession' => ['update', 'pak_conversion_runs'], 'run' => ['insert into', 'pak_conversion_runs'],
            'period' => ['insert into', 'pak_conversion_periods'], 'case' => ['update', 'pak_cases'],
            'audit' => ['insert into', 'pak_events'],
        };
        DB::listen(function ($q) use ($stage, $operation, $table, &$hits) {
            if ($hits === 0 && str_starts_with($q->sql, $operation.' "'.$table.'"')) {
                $hits++;
                $this->assertSame('SUPERSEDED', $this->predecessor->fresh()->status->value);
                $this->assertSame($stage === 'supersession' ? 1 : 2, PakConversionRun::count());
                $this->assertSame(match ($stage) {
                    'supersession', 'run' => 4, 'period' => 5, default => 8
                }, PakConversionPeriod::count());
                $this->assertSame($this->predecessor->case_lock_version + (in_array($stage, ['case', 'audit']) ? 1 : 0), $this->case->fresh()->lock_version);
                $this->assertSame($stage === 'audit' ? 1 : 0, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
                throw new \RuntimeException('Synthetic replacement write fault');
            }
        });
        try {
            app(ConversionService::class)->replace($this->actor, $request);
            $this->fail('Fault did not escape.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic replacement write fault', $error->getMessage());
        }
        $this->assertSame(1, $hits);
        $this->assertSame(1, $attempts);
        $this->assertUnchanged($before);
    }

    public static function commitFailures(): array
    {
        return [['before_commit'], ['after_commit'], ['pdo_committed_before_bookkeeping']];
    }

    #[DataProvider('commitFailures')]
    public function test_commit_failure_retries_only_when_proven_uncommitted(string $stage): void
    {
        $this->renameLevel('Commit fixture');
        $request = $this->replacementRequest();
        $before = $this->state();
        $pdoError = new \PDOException('Synthetic exact deadlock');
        $pdoError->errorInfo = ['40001', 1213, 'Synthetic'];
        $fault = new QueryException('sqlite', 'synthetic', [], $pdoError);
        $attempts = $callbacks = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$attempts) {
            $attempts++;
        });
        PakEvent::created(function ($event) use (&$callbacks) {
            if ($event->event_code === 'CONVERSION_RUN_REPLACED') {
                DB::afterCommit(function () use (&$callbacks) {
                    $callbacks++;
                });
            }
        });
        $dispatcher = DB::connection()->getEventDispatcher();
        $event = $stage === 'after_commit' ? TransactionCommitted::class : TransactionCommitting::class;
        $dispatcher->listen($event, function () use ($stage, $fault) {
            $this->assertSame(2, PakConversionRun::count());
            $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
            if ($stage === 'pdo_committed_before_bookkeeping') {
                DB::connection()->getPdo()->commit();
            }
            throw $fault;
        });
        try {
            app(ConversionService::class)->replace($this->actor, $request);
            $this->fail('Commit fault swallowed.');
        } catch (ValidationException $error) {
            $this->assertSame('before_commit', $stage);
            $this->assertSame(['conversion' => ['CONCURRENCY_RETRY_EXHAUSTED']], $error->errors());
        } catch (QueryException $error) {
            $this->assertNotSame('before_commit', $stage);
            $this->assertSame($fault, $error);
        } finally {
            $dispatcher->forget($event);
        }
        $this->assertSame($stage === 'before_commit' ? 3 : 1, $attempts);
        $this->assertSame($stage === 'after_commit' ? 1 : 0, $callbacks);
        if ($stage === 'before_commit') {
            $this->assertUnchanged($before);
        } else {
            $this->assertSame(2, PakConversionRun::count());
            $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
            $this->assertSame($request->expected_case_lock_version + 1, $this->case->fresh()->lock_version);
            $this->assertClean();
        }
    }

    private function databaseFault(string $state = '40001', int $vendor = 1213): QueryException
    {
        $previous = new \PDOException('Synthetic database fault');
        $previous->errorInfo = [$state, $vendor, 'Synthetic'];

        return new QueryException('sqlite', 'synthetic', [], $previous);
    }

    public static function retryWrites(): array
    {
        return [['supersession', 1], ['audit', 1], ['audit', 2], ['audit', 3]];
    }

    #[DataProvider('retryWrites')]
    public function test_deadlocks_rollback_all_state_before_fresh_retry(string $boundary, int $failures): void
    {
        $this->renameLevel('Retry fixture');
        $request = $this->replacementRequest();
        $before = $this->state();
        $attempts = $discoveries = $hits = $callbacks = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$attempts, $before) {
            $this->assertUnchanged($before);
            $attempts++;
        });
        PakConversionRun::updating(function () {
            $this->fail('Generic run update must not be used.');
        });
        DB::listen(function ($q) use (&$discoveries, &$hits, &$callbacks, $failures, $boundary) {
            if (str_starts_with($q->sql, 'select "person_id" from "pak_cases"')) {
                $discoveries++;
            }
            $prefix = $boundary === 'audit' ? 'insert into "pak_events"' : 'update "pak_conversion_runs"';
            if (str_starts_with($q->sql, $prefix)) {
                $hits++;
                DB::afterCommit(function () use (&$callbacks) {
                    $callbacks++;
                });
                $this->assertSame('SUPERSEDED', $this->predecessor->fresh()->status->value);
                if ($boundary === 'audit') {
                    $this->assertSame(2, PakConversionRun::count());
                    $this->assertSame(8, PakConversionPeriod::count());
                    $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
                }
                if ($hits <= $failures) {
                    throw $this->databaseFault();
                }
            }
        });
        try {
            $new = app(ConversionService::class)->replace($this->actor, $request);
            $this->assertLessThan(3, $failures);
            $this->assertSame(2, $new->revision_number);
            $this->assertSame(2, PakConversionRun::count());
            $this->assertSame(8, PakConversionPeriod::count());
            $this->assertSame(1, PakConversionRun::whereNotNull('active_case_id')->count());
            $this->assertSame($request->expected_case_lock_version + 1, $this->case->fresh()->lock_version);
            $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
            $this->assertSame(1, $callbacks);
        } catch (ValidationException $error) {
            $this->assertSame(3, $failures);
            $this->assertSame(['conversion' => ['CONCURRENCY_RETRY_EXHAUSTED']], $error->errors());
            $this->assertSame(0, $callbacks);
            $this->assertUnchanged($before);
        }
        $this->assertSame(min(3, $failures + 1), $attempts);
        $this->assertSame($attempts, $discoveries);
        $this->assertSame($attempts, $hits);
        $this->assertClean();
    }

    public function test_fresh_retry_rebuilds_changed_dependency_and_stops_on_stale_snapshot(): void
    {
        $this->renameLevel('State A');
        $request = $this->replacementRequest();
        $before = $this->state();
        $attempts = $discoveries = $faults = 0;
        $mutating = false;
        $grammar = $this->observeLockReads();
        DB::connection()->beforeStartingTransaction(function () use (&$attempts, &$mutating) {
            $this->assertClean();
            if (! $mutating) {
                $attempts++;
            }
        });
        DB::listen(function ($q) use (&$discoveries, &$faults) {
            if (str_starts_with($q->sql, 'select "person_id" from "pak_cases"')) {
                $discoveries++;
            }
            if ($faults === 0 && str_starts_with($q->sql, 'insert into "pak_events"')) {
                $faults++;
                throw $this->databaseFault();
            }
        });
        $dispatcher = DB::connection()->getEventDispatcher();
        $dispatcher->listen(TransactionRolledBack::class, function () use (&$mutating, &$before, &$attempts) {
            $this->assertClean();
            if ($attempts === 1) {
                $this->assertUnchanged($before);
                $mutating = true;
                $this->renameLevel('State B committed between attempts');
                $mutating = false;
                $before = $this->state();
            }
        });
        try {
            app(ConversionService::class)->replace($this->actor, $request);
            $this->fail('Stale retry accepted.');
        } catch (ValidationException $error) {
            $this->assertSame(['snapshot'], array_keys($error->errors()));
        } finally {
            $dispatcher->forget(TransactionRolledBack::class);
        }
        $this->assertSame(2, $attempts);
        $this->assertSame(2, $discoveries);
        $this->assertSame(2, count(array_filter($grammar->reads, fn ($r) => $r['table'] === 'pak_conversion_runs')));
        $this->assertUnchanged($before);
        $fresh = $this->preview();
        $this->assertNotSame($request->expected_dependency_fingerprint, $fresh->dependency_fingerprint);
        $this->assertNotSame($request->expected_snapshot_fingerprint, $fresh->snapshot_fingerprint);
    }

    public static function nonRetryFaults(): array
    {
        return [['40001', 1062], ['HY000', 1213], ['HY000', 1205], ['runtime', 0]];
    }

    #[DataProvider('nonRetryFaults')]
    public function test_nonapproved_write_failure_does_not_retry(string $state, int $vendor): void
    {
        $this->renameLevel('Nonretry fixture');
        $request = $this->replacementRequest();
        $before = $this->state();
        $fault = $state === 'runtime' ? new \RuntimeException('Synthetic application failure') : $this->databaseFault($state, $vendor);
        $attempts = $discoveries = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$attempts) {
            $attempts++;
        });
        DB::listen(function ($q) use (&$discoveries, $fault) {
            if (str_starts_with($q->sql, 'select "person_id" from "pak_cases"')) {
                $discoveries++;
            }
            if (str_starts_with($q->sql, 'update "pak_conversion_runs"')) {
                throw $fault;
            }
        });
        try {
            app(ConversionService::class)->replace($this->actor, $request);
            $this->fail('Fault swallowed.');
        } catch (\RuntimeException $error) {
            $this->assertSame($fault, $error);
        }
        $this->assertSame(1, $attempts);
        $this->assertSame(1, $discoveries);
        $this->assertUnchanged($before);
    }

    public function test_exact_deadlock_during_discovery_has_no_owned_rollback_proof(): void
    {
        $request = $this->replacementRequest();
        $before = $this->state();
        $attempts = $discoveries = 0;
        $fault = $this->databaseFault();
        DB::connection()->beforeStartingTransaction(function () use (&$attempts) {
            $attempts++;
        });
        DB::listen(function ($q) use (&$discoveries, $fault) {
            if (str_starts_with($q->sql, 'select "person_id" from "pak_cases"')) {
                $discoveries++;
                throw $fault;
            }
        });
        try {
            app(ConversionService::class)->replace($this->actor, $request);
            $this->fail('Discovery fault swallowed.');
        } catch (QueryException $error) {
            $this->assertSame($fault, $error);
        }
        $this->assertSame(0, $attempts);
        $this->assertSame(1, $discoveries);
        $this->assertUnchanged($before);
    }

    private function observeLockReads(): SQLiteGrammar
    {
        $grammar = new class(DB::connection()) extends SQLiteGrammar
        {
            public array $reads = [];

            public function compileSelect(Builder $query)
            {
                if ($query->lock !== null) {
                    $this->reads[] = ['table' => $query->from, 'lock' => $query->lock];
                }

                return parent::compileSelect($query);
            }
        };
        DB::connection()->setQueryGrammar($grammar);

        return $grammar;
    }

    public function test_lock_intent_preserves_order_and_never_relocks_runs_after_histories(): void
    {
        $this->renameLevel('Lock intent fixture');
        $request = $this->replacementRequest();
        $grammar = $this->observeLockReads();
        app(ConversionService::class)->replace($this->actor, $request);
        $tables = array_column($grammar->reads, 'table');
        $previous = -1;
        foreach (['pak_cases', 'people', 'pak_rule_sets', 'performance_predicate_rules', 'ak_coefficient_rules',
            'functional_position_levels', 'functional_positions', 'functional_levels', 'functional_categories',
            'pak_conversion_runs', 'performance_evaluations', 'employee_position_histories'] as $table) {
            $index = array_search($table, $tables, true);
            $this->assertNotFalse($index, $table);
            $this->assertGreaterThan($previous, $index, $table);
            $previous = $index;
        }
        $runReads = array_values(array_filter($grammar->reads, fn ($r) => $r['table'] === 'pak_conversion_runs'));
        $this->assertSame([['table' => 'pak_conversion_runs', 'lock' => true]], $runReads);
        $afterEvaluations = array_slice($tables, array_search('performance_evaluations', $tables, true));
        foreach (['pak_conversion_runs', 'functional_position_levels', 'functional_positions', 'functional_levels', 'functional_categories'] as $table) {
            $this->assertNotContains($table, $afterEvaluations);
        }
    }

    public function test_replacement_mapping_plan_drift_rejects_without_late_mapping_lock(): void
    {
        DB::table('functional_position_levels')->where('functional_level_id', $this->level->id)->delete();
        $request = $this->replacementRequest();
        $before = $this->state();
        $grammar = $this->observeLockReads();
        $armed = true;
        DB::listen(function ($q) use (&$armed) {
            if ($armed && DB::transactionLevel() === 1 && str_contains($q->sql, 'from "pak_cases"')) {
                $armed = false;
                FunctionalPositionLevel::create(['functional_position_id' => $this->history->functional_position_id, 'functional_level_id' => $this->level->id]);
            }
        });
        try {
            app(ConversionService::class)->replace($this->actor, $request);
            $this->fail('Unplanned mapping accepted.');
        } catch (ValidationException $error) {
            $this->assertSame(['snapshot' => ['Dependency lock plan changed. Obtain a new preview.']], $error->errors());
        }
        $this->assertFalse($armed);
        $this->assertNotContains('functional_position_levels', array_column($grammar->reads, 'table'));
        $this->assertUnchanged($before);
    }

    public function test_source_integrity_blocks_before_supersession(): void
    {
        DB::table('performance_evaluations')->update(['content_fingerprint' => 'corrupt']);
        $request = $this->replacementRequest();
        $before = $this->state();
        try {
            app(ConversionService::class)->replace($this->actor, $request);
            $this->fail('Blocking source issue saved.');
        } catch (ValidationException $error) {
            $this->assertSame(['conversion'], array_keys($error->errors()));
        }
        $this->assertUnchanged($before);
    }
}
