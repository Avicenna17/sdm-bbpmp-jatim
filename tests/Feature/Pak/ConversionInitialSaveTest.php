<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\Exceptions\ConversionRuleException;
use App\Domain\Pak\Rules\ConversionSaveability;
use App\Domain\Pak\Services\ConversionService;
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

class ConversionInitialSaveTest extends TestCase
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
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertSame('pak_02a1_test', getenv('PAK_DISPOSABLE_DB'));
            $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
            $this->assertSame('33318', (string) DB::connection()->getConfig('port'));
            $this->assertSame('pak_02a1_test', DB::connection()->getDatabaseName());
        }
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

    private function assertEmptySave(): void
    {
        $this->assertSame(0, PakConversionRun::count());
        $this->assertSame(0, PakConversionPeriod::count());
        $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->assertSame($this->case->lock_version, $this->case->fresh()->lock_version);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_synthetic_save_preserves_exact_results_version_hashes_and_first_use(): void
    {
        $request = $this->request();
        $before = $this->rule->fresh()->getRawOriginal('first_used_at');
        $run = $this->save($request);
        $this->assertSame(['25/6', '25/1', '25/1', '125/6'], $run->periods->map(fn ($p) => $p->result_numerator.'/'.$p->result_denominator)->all());
        $this->assertSame('75/1', $run->total_numerator.'/'.$run->total_denominator);
        $this->assertSame(1, $run->revision_number);
        $this->assertNull($run->predecessor_run_id);
        $this->assertNull($run->revision_reason);
        $this->assertSame($request->expected_case_lock_version + 1, $run->case_lock_version);
        $this->assertSame($run->case_lock_version, $this->case->fresh()->lock_version);
        $this->assertSame($request->expected_dependency_fingerprint, $run->dependency_fingerprint);
        $this->assertSame($request->expected_snapshot_fingerprint, $run->snapshot_fingerprint);
        $this->assertSame($before, $this->rule->fresh()->getRawOriginal('first_used_at'));
        $event = PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->sole();
        $this->assertSame('PakConversionRun', $event->subject_type);
        $this->assertSame($run->id, $event->subject_id);
        $this->assertSame(['after'], array_keys($event->payload));
        $this->assertSame(['case_id', 'run_id', 'revision_number', 'status', 'issue_codes'], array_keys($event->payload['after']));
        $this->assertSame(0, DB::transactionLevel());
        $this->expectException(ValidationException::class);
        $run->update(['total_numerator' => '76']);
    }

    public static function staleCases(): array
    {
        return array_map(fn ($field) => [$field], ['expected_case_lock_version', 'expected_dependency_fingerprint', 'expected_snapshot_fingerprint']);
    }

    #[DataProvider('staleCases')]
    public function test_each_stale_precondition_leaves_zero_writes(string $field): void
    {
        $data = get_object_vars($this->request());
        $data[$field] = $field === 'expected_case_lock_version' ? $data[$field] + 1 : str_repeat('0', 64);
        try {
            $this->save(ConversionSaveRequest::from($data));
            $this->fail('Stale precondition accepted');
        } catch (ValidationException) {
            $this->assertEmptySave();
        }
    }

    public static function diagnostics(): array
    {
        return array_map(fn ($code) => [$code], ConversionSaveability::REVIEW_CODES);
    }

    #[DataProvider('diagnostics')]
    public function test_review_saveable_diagnostics_persist_from_real_orchestration(string $code): void
    {
        $start = '2023-11-01';
        switch ($code) {
            case 'PARTIAL_MONTH_REQUIRES_REVIEW': $start = '2023-11-02';
                break;
            case 'SOURCE_NOT_FOUND': DB::table('performance_evaluations')->delete();
                break;
            case 'AMBIGUOUS_SOURCE_SELECTION':
                $this->evaluation(2023, 'Q4');
                $this->evaluation(2023, 'Q4');
                DB::table('performance_evaluations')->where('performance_year', 2023)->where('evaluation_type', 'ANNUAL')->delete();
                break;
            case 'SOURCE_POSITION_LINK_REQUIRES_REVIEW': $this->evaluation(2023);
                break;
            case 'MISSING_POSITION_HISTORY': DB::table('employee_position_histories')->update(['superseded_at' => '2026-01-01 00:00:00']);
                break;
            case 'AMBIGUOUS_POSITION_HISTORY':
                $copy = $this->history->replicate();
                $copy->save();
                break;
            case 'RULE_VALIDITY_REQUIRES_REVIEW': DB::table('pak_rule_sets')->update(['valid_from' => null]);
                break;
            case 'LEGACY_REGIME_REQUIRES_REVIEW': DB::table('employee_position_histories')->update(['tmt_start' => '2022-01-01']);
                break;
            case 'REGIME_EVIDENCE_REQUIRES_REVIEW': DB::table('employee_position_histories')->update(['sk_number' => '']);
                break;
            case 'PREDICATE_RULE_NOT_FOUND': DB::table('performance_predicate_rules')->delete();
                break;
            case 'COEFFICIENT_RULE_NOT_FOUND': DB::table('ak_coefficient_rules')->delete();
                break;
            case 'MISSING_FUNCTIONAL_LEVEL': DB::table('employee_position_histories')->update(['functional_level_id' => null]);
                break;
        }
        $run = $this->save($this->request($start));
        $this->assertContains($code, array_column($run->original_issues, 'issue_code'));
        $this->assertSame('REQUIRES_REVIEW', $run->status->value);
        $this->assertNull($run->total_numerator);
        $this->assertNull($run->total_denominator);
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        if (in_array($code, ['PARTIAL_MONTH_REQUIRES_REVIEW', 'RULE_VALIDITY_REQUIRES_REVIEW'], true)) {
            $this->assertSame(0, $run->periods()->count());
            $this->assertSame($start, $run->requested_start->format('Y-m-d'));
        }
    }

    public static function faultStages(): array
    {
        return [['pak_conversion_runs'], ['pak_conversion_periods'], ['pak_cases'], ['pak_events']];
    }

    #[DataProvider('faultStages')]
    public function test_fault_rolls_back_all_state_without_retry(string $table): void
    {
        $request = $this->request();
        $hits = 0;
        DB::listen(function ($event) use ($table, &$hits) {
            if ((str_starts_with($event->sql, 'insert into') || str_starts_with($event->sql, 'update')) && str_contains($event->sql, '"'.$table.'"')) {
                $hits++;
                throw new \RuntimeException('Synthetic write fault');
            }
        });
        try {
            $this->save($request);
            $this->fail('Fault must escape');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic write fault', $e->getMessage());
            $this->assertSame(1, $hits);
            $this->assertEmptySave();
        }
    }

    public static function existingStatuses(): array
    {
        return [['PREVIEW'], ['REQUIRES_REVIEW'], ['SUPERSEDED']];
    }

    #[DataProvider('existingStatuses')]
    public function test_any_existing_run_rejects_without_returning_success(string $status): void
    {
        $run = $this->save();
        DB::table('pak_conversion_runs')->where('id', $run->id)->update(['status' => $status, 'active_case_id' => $status === 'SUPERSEDED' ? null : $this->case->id]);
        $request = $this->request();
        try {
            $this->save($request);
            $this->fail('Existing history accepted');
        } catch (ValidationException) {
            $this->assertSame(1, PakConversionRun::count());
            $this->assertSame(4, PakConversionPeriod::count());
            $this->assertSame($run->case_lock_version, $this->case->fresh()->lock_version);
            $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        }
    }

    public function test_outer_transaction_rejected_without_touching_caller(): void
    {
        $request = $this->request();
        DB::beginTransaction();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        try {
            $this->save($request);
            $this->fail('Outer transaction accepted');
        } catch (\LogicException $e) {
            $this->assertSame('Conversion operation requires ownership of a top-level transaction.', $e->getMessage());
            $this->assertSame([], $queries);
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertEmptySave();
    }

    public static function permissions(): array
    {
        return array_map(fn ($p) => [$p], [...ConversionService::PERMISSIONS, 'pak.access', 'inactive']);
    }

    #[DataProvider('permissions')]
    public function test_authorization_prevents_writes(string $permission): void
    {
        $request = $this->request();
        if ($permission === 'inactive') {
            $this->actor->forceFill(['is_active' => false])->save();
        } else {
            $this->denied = $permission;
        }
        try {
            $this->save($request);
            $this->fail('Unauthorized save');
        } catch (HttpException) {
            $this->assertEmptySave();
        }
    }

    public function test_first_use_integrity_blocks_without_repair(): void
    {
        $request = $this->request();
        DB::table('pak_rule_sets')->update(['first_used_at' => null]);
        try {
            $this->save($request);
            $this->fail('Missing marker accepted');
        } catch (ConversionRuleException $e) {
            $this->assertSame('RULE_INTEGRITY_ERROR', $e->issue_code);
            $this->assertNull($this->rule->fresh()->first_used_at);
            $this->assertEmptySave();
        }
    }

    public static function dependencyChanges(): array
    {
        return [['evaluation'], ['history'], ['rule'], ['master']];
    }

    #[DataProvider('dependencyChanges')]
    public function test_rebuild_rejects_actual_dependency_drift(string $dependency): void
    {
        $request = $this->request();
        match ($dependency) {
            'evaluation' => DB::table('performance_evaluations')->update(['content_fingerprint' => str_repeat('c', 64)]),
            'history' => DB::table('employee_position_histories')->update(['source_ref' => 'Changed synthetic evidence']),
            'rule' => DB::table('pak_rule_sets')->update(['reference' => 'Changed synthetic reference']),
            'master' => DB::table('functional_levels')->update(['name' => 'Changed synthetic label']),
        };
        try {
            $this->save($request);
            $this->fail('Stale evidence saved');
        } catch (ValidationException) {
            $this->assertEmptySave();
        }
    }

    public function test_source_integrity_blocks_even_with_matching_preview_hashes(): void
    {
        DB::table('performance_evaluations')->update(['content_fingerprint' => 'corrupt']);
        $request = $this->request();
        try {
            $this->save($request);
            $this->fail('Source integrity persisted');
        } catch (ValidationException) {
            $this->assertEmptySave();
        }
    }

    public static function ruleFailures(): array
    {
        return [['missing'], ['status'], ['validity'], ['child']];
    }

    #[DataProvider('ruleFailures')]
    public function test_rule_rejection_rolls_back(string $fault): void
    {
        $request = $this->request();
        match ($fault) {
            'missing' => DB::table('pak_cases')->update(['pak_rule_set_id' => null]),
            'status' => DB::table('pak_rule_sets')->update(['status' => 'CORRUPT']),
            'validity' => DB::table('pak_rule_sets')->update(['valid_from' => '2027-01-01']),
            'child' => DB::table('performance_predicate_rules')->update(['predicate_name' => '']),
        };
        try {
            $this->save($request);
            $this->fail('Corrupt rule saved');
        } catch (ConversionRuleException) {
            $this->assertEmptySave();
        }
    }

    public static function invalidRanges(): array
    {
        return [['bad', '2026-10-31'], ['2026-10-31', '2023-11-01'], ['2022-01-01', '2026-10-31']];
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_range_rolls_back(string $start, string $end): void
    {
        $data = get_object_vars($this->request());
        $data['requested_start'] = $start;
        $data['requested_end'] = $end;
        try {
            $this->save(ConversionSaveRequest::from($data));
            $this->fail('Invalid range saved');
        } catch (ValidationException) {
            $this->assertEmptySave();
        }
    }

    public function test_changed_master_lock_plan_fails_without_late_master_acquisition(): void
    {
        $request = $this->request();
        $newLevel = FunctionalLevel::create(['functional_category_id' => $this->level->functional_category_id, 'code' => 'NEW', 'name' => 'Synthetic new dependency']);
        $armed = true;
        DB::listen(function ($q) use (&$armed, $newLevel) {
            if ($armed && DB::transactionLevel() === 1 && str_contains($q->sql, 'from "pak_cases"')) {
                $armed = false;
                DB::table('employee_position_histories')->update(['functional_level_id' => $newLevel->id]);
            }
        });
        try {
            $this->save($request);
            $this->fail('Changed plan accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('snapshot', $e->errors());
            $this->assertEmptySave();
            $this->assertSame($this->level->id, $this->history->fresh()->functional_level_id);
        }
    }

    /** Observe lock intent before SQLite grammar omits the SQL locking clause. */
    private function observeLockReads(): SQLiteGrammar
    {
        $grammar = new class(DB::connection()) extends SQLiteGrammar
        {
            public array $reads = [];

            public function compileSelect(Builder $query)
            {
                if ($query->lock !== null) {
                    $this->reads[] = ['table' => $query->from, 'lock' => $query->lock, 'bindings' => $query->getBindings()];
                }

                return parent::compileSelect($query);
            }
        };
        DB::connection()->setQueryGrammar($grammar);

        return $grammar;
    }

    public static function mappingDrifts(): array
    {
        return [['cross_pair'], ['new_pair'], ['new_row_for_same_pair']];
    }

    #[DataProvider('mappingDrifts')]
    public function test_exact_mapping_drift_rejects_without_late_lock_or_writes(string $drift): void
    {
        $positionId = $this->history->functional_position_id;
        $levelId = $this->level->id;
        if ($drift === 'new_row_for_same_pair') {
            DB::table('functional_position_levels')->delete();
        } else {
            $position = FunctionalPosition::create(['code' => 'SYNTHETIC-P2', 'name' => 'Synthetic second position']);
            $level = FunctionalLevel::create(['functional_category_id' => $this->level->functional_category_id,
                'code' => 'SYNTHETIC-L2', 'name' => 'Synthetic second level']);
            FunctionalPositionLevel::create(['functional_position_id' => $position->id, 'functional_level_id' => $level->id]);
            $levelId = $level->id;
            if ($drift === 'cross_pair') {
                $other = $this->history->replicate();
                $other->fill(['functional_position_id' => $position->id, 'functional_level_id' => $level->id,
                    'superseded_at' => '2026-01-01 00:00:00'])->save();
            } else {
                $positionId = $position->id;
            }
        }
        $unplannedId = null;
        if ($drift === 'cross_pair') {
            $unplannedId = FunctionalPositionLevel::create(['functional_position_id' => $positionId, 'functional_level_id' => $levelId])->id;
        } elseif ($drift === 'new_pair') {
            $unplannedId = FunctionalPositionLevel::where('functional_position_id', $positionId)->where('functional_level_id', $levelId)->sole()->id;
        }
        $request = $this->request();
        $marker = $this->rule->fresh()->getRawOriginal('first_used_at');
        $originalGrammar = DB::connection()->getQueryGrammar();
        $grammar = $this->observeLockReads();
        $armed = true;
        DB::listen(function ($q) use (&$armed, &$unplannedId, $drift, $positionId, $levelId) {
            if ($armed && DB::transactionLevel() === 1 && str_contains($q->sql, 'from "pak_cases"')) {
                $armed = false;
                if ($drift === 'new_row_for_same_pair') {
                    $unplannedId = FunctionalPositionLevel::create(['functional_position_id' => $positionId, 'functional_level_id' => $levelId])->id;
                } else {
                    DB::table('employee_position_histories')->where('id', $this->history->id)->update([
                        'functional_position_id' => $positionId, 'functional_level_id' => $levelId]);
                }
            }
        });
        try {
            $this->save($request);
            $this->fail('Unplanned exact mapping accepted');
        } catch (ValidationException $e) {
            $this->assertSame(['Dependency lock plan changed. Obtain a new preview.'], $e->errors()['snapshot']);
            $this->assertFalse($armed, 'Drift injection must execute');
            $this->assertNotNull($unplannedId);
            foreach ($grammar->reads as $read) {
                if ($read['table'] === 'functional_position_levels') {
                    $this->assertNotContains($unplannedId, $read['bindings'], 'Unplanned mapping must never be locked');
                }
            }
            $evaluationLocks = array_keys(array_filter($grammar->reads, fn ($r) => $r['table'] === 'performance_evaluations'));
            $this->assertNotEmpty($evaluationLocks);
            foreach ($grammar->reads as $index => $read) {
                if ($read['table'] === 'functional_position_levels') {
                    $this->assertLessThan(min($evaluationLocks), $index, 'No late mapping acquisition');
                }
            }
            $this->assertEmptySave();
            $this->assertSame($marker, $this->rule->fresh()->getRawOriginal('first_used_at'));
        } finally {
            DB::connection()->setQueryGrammar($originalGrammar);
        }
    }

    public function test_unchanged_absent_mapping_does_not_introduce_new_business_rejection(): void
    {
        DB::table('functional_position_levels')->delete();
        $run = $this->save();
        $this->assertSame('75/1', $run->total_numerator.'/'.$run->total_denominator);
        $this->assertSame(0, FunctionalPositionLevel::count());
    }

    public static function sqlFaults(): array
    {
        return [['40001', 1213], ['23000', 1062]];
    }

    #[DataProvider('sqlFaults')]
    public function test_database_error_is_not_retried_in_b2(string $state, int $vendor): void
    {
        $request = $this->request();
        $hits = 0;
        DB::listen(function ($q) use (&$hits, $state, $vendor) {
            if (str_starts_with($q->sql, 'insert into "pak_conversion_runs"')) {
                $hits++;
                $error = new \PDOException('Synthetic database fault');
                $error->errorInfo = [$state, $vendor, 'Synthetic'];
                throw new QueryException('sqlite', 'synthetic', [], $error);
            }
        });
        try {
            $this->save($request);
            $this->fail('Database fault swallowed');
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0]);
            $this->assertSame(1, $hits);
            $this->assertEmptySave();
        }
    }

    public function test_coordination_resource_order_precedes_orchestration_and_writes(): void
    {
        $request = $this->request();
        $originalGrammar = DB::connection()->getQueryGrammar();
        $grammar = $this->observeLockReads();
        try {
            $run = $this->save($request);
            $this->assertSame('75/1', $run->total_numerator.'/'.$run->total_denominator);
            $reads = $grammar->reads;
            $indexes = fn ($table) => array_keys(array_filter($reads, fn ($read) => $read['table'] === $table));
            $previous = -1;
            foreach (['pak_cases', 'people', 'pak_rule_sets', 'functional_position_levels', 'functional_positions', 'functional_levels', 'functional_categories', 'pak_conversion_runs'] as $table) {
                $matches = $indexes($table);
                $this->assertNotEmpty($matches, $table);
                $this->assertGreaterThan($previous, min($matches), $table);
                $previous = max($matches);
            }
            $evaluations = $indexes('performance_evaluations');
            $histories = $indexes('employee_position_histories');
            $this->assertNotEmpty($evaluations);
            $this->assertNotEmpty($histories);
            $this->assertGreaterThan($previous, min($evaluations));
            $this->assertGreaterThan(min($evaluations), min($histories));
            // All initial evaluation PK locks precede the first history lock. Later reads are reentrant.
            $initialEvaluations = array_filter($reads, fn ($read, $i) => $i < min($histories) && $read['table'] === 'performance_evaluations', ARRAY_FILTER_USE_BOTH);
            $this->assertSame(PerformanceEvaluation::orderBy('id')->pluck('id')->all(), array_map(fn ($r) => $r['bindings'][0], array_values($initialEvaluations)));
            foreach (['functional_position_levels', 'functional_positions', 'functional_levels', 'functional_categories'] as $table) {
                $this->assertLessThan(min($evaluations), max($indexes($table)), 'No late master lock: '.$table);
                foreach ($indexes($table) as $i) {
                    $this->assertFalse($reads[$i]['lock'], 'Shared reader coordination');
                }
            }
            $mappingLocks = array_map(fn ($i) => $reads[$i]['bindings'][0], $indexes('functional_position_levels'));
            $this->assertSame(FunctionalPositionLevel::orderBy('id')->pluck('id')->all(), $mappingLocks, 'Exact planned mapping locked');
        } finally {
            DB::connection()->setQueryGrammar($originalGrammar);
        }
    }

    public static function lockedCases(): array
    {
        return [['status', 'FINAL'], ['finalized_at', '2026-01-01 00:00:00']];
    }

    #[DataProvider('lockedCases')]
    public function test_non_draft_or_finalized_case_rejects(string $field, string $value): void
    {
        $request = $this->request();
        DB::table('pak_cases')->where('id', $this->case->id)->update([$field => $value]);
        try {
            $this->save($request);
            $this->fail('Locked case accepted');
        } catch (ValidationException) {
            $this->assertEmptySave();
        }
    }
}
