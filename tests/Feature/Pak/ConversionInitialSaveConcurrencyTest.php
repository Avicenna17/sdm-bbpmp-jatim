<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
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
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConversionInitialSaveConcurrencyTest extends TestCase
{
    private string $root;

    private User $actor;

    private PakCase $case;

    private PakRuleSet $rule;

    private EmployeePositionHistory $history;

    private FunctionalLevel $level;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PAK_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Opt in PAK_CONCURRENCY=1 with disposable MariaDB 10.5.11 on 127.0.0.1:33318/pak_02a1_test.');
        }
        $config = DB::connection()->getConfig();
        $this->assertSame('mysql', $config['driver']);
        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame(33318, (int) $config['port']);
        $this->assertSame('33318', getenv('PAK_CONCURRENCY_PORT'));
        $this->assertSame('pak_02a1_test', getenv('PAK_DISPOSABLE_DB'));
        $this->assertSame('pak_02a1_test', $config['database']);
        $identity = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS db, CONNECTION_ID() AS connection_id');
        $this->assertSame('pak_02a1_test', $identity->db);
        $this->assertStringStartsWith('10.5.11-MariaDB', $identity->version);
        fwrite(STDOUT, "\nCONVERSION_SERVER ".json_encode($identity)."\n");
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->root = storage_path('framework/testing/conversion-concurrency-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0700, true);
        $this->actor = User::factory()->create(['is_active' => true]);
        foreach (['pak.access', ...ConversionService::PERMISSIONS] as $permission) {
            $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
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

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    private function worker(string $name, ConversionSaveRequest $request, bool $hold): Process
    {
        $input = ['operation' => 'conversion', 'request' => get_object_vars($request),
            'actor' => $this->actor->id, 'db' => DB::connection()->getConfig(), 'root' => $this->root,
            'worker' => $name, 'barrier' => $hold ? 'hold' : 'none', 'lock_table' => 'pak_cases'];

        return new Process([PHP_BINARY, base_path('tests/Support/Pak/concurrency-worker.php')], base_path(),
            ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => 'bootstrap/cache/skp-test-unused-config.php'],
            json_encode($input, JSON_THROW_ON_ERROR), 25);
    }

    private function await(callable $condition): void
    {
        $deadline = microtime(true) + 12;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $this->fail('Conversion barrier/lock-wait timeout.');
            }
            usleep(200000);
        }
    }

    private function workerResult(Process $worker): array
    {
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), 'Worker failed; private stderr omitted.');
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertFalse($result['pdo_active']);
        $this->assertSame(1, $result['attempts']);

        return $result;
    }

    private function committedState(ConversionSaveRequest $request, string $marker): void
    {
        DB::purge();
        $case = PakCase::findOrFail($request->pak_case_id);
        $this->assertSame(1, PakConversionRun::count());
        $run = PakConversionRun::where('pak_case_id', $case->id)->sole();
        $this->assertSame(1, $run->revision_number);
        $this->assertNull($run->predecessor_run_id);
        $this->assertNull($run->revision_reason);
        $this->assertSame($case->id, $run->active_case_id);
        $this->assertSame($case->person_id, $run->person_id);
        $this->assertSame($case->pak_rule_set_id, $run->pak_rule_set_id);
        $this->assertSame($request->expected_dependency_fingerprint, $run->dependency_fingerprint);
        $this->assertSame($request->expected_snapshot_fingerprint, $run->snapshot_fingerprint);
        $this->assertSame('75/1', $run->total_numerator.'/'.$run->total_denominator);
        $this->assertSame(4, PakConversionPeriod::count());
        $this->assertSame(['25/6', '25/1', '25/1', '125/6'], $run->periods()->orderBy('line_number')->get()
            ->map(fn ($period) => $period->result_numerator.'/'.$period->result_denominator)->all());
        $this->assertSame($request->expected_case_lock_version + 1, $case->lock_version);
        $this->assertSame($case->lock_version, $run->case_lock_version);
        $event = PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->sole();
        $this->assertSame('PakConversionRun', $event->subject_type);
        $this->assertSame($run->id, $event->subject_id);
        $this->assertSame($marker, PakRuleSet::findOrFail($case->pak_rule_set_id)->getRawOriginal('first_used_at'));
        fwrite(STDOUT, "\nCONVERSION_FINAL ".json_encode(['run_count' => 1, 'period_count' => 4,
            'audit_count' => 1, 'total' => '75/1', 'version_before' => $request->expected_case_lock_version,
            'version_after' => $case->lock_version, 'first_use_before' => $marker, 'first_use_after' => $marker,
            'parent_connection' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id])."\n");
    }

    public function test_single_worker_root_commit_smoke(): void
    {
        $request = $this->request();
        $marker = $this->rule->fresh()->getRawOriginal('first_used_at');
        $worker = $this->worker('smoke', $request, false);
        try {
            $worker->start();
            $result = $this->workerResult($worker);
            $this->assertTrue($result['success']);
            fwrite(STDOUT, "\nCONVERSION_SMOKE ".json_encode($result)."\n");
            $this->committedState($request, $marker);
        } finally {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
    }

    public static function repetitions(): array
    {
        return [[1], [2], [3]];
    }

    #[DataProvider('repetitions')]
    public function test_same_case_serializes_with_observed_lock_wait(int $repetition): void
    {
        $request = $this->request();
        $marker = $this->rule->fresh()->getRawOriginal('first_used_at');
        $workers = [$this->worker('a', $request, true), $this->worker('b', $request, false)];
        try {
            $workers[0]->start();
            $this->await(fn () => is_file($this->root.'/a.held'));
            $workers[1]->start();
            $this->await(fn () => is_file($this->root.'/b.connection'));
            $ids = [(int) file_get_contents($this->root.'/a.connection'), (int) file_get_contents($this->root.'/b.connection')];
            $this->assertNotSame($ids[0], $ids[1]);
            // A third connection observes actual InnoDB wait metadata, never a timing inference.
            DB::purge();
            $observer = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertNotContains($observer, $ids);
            $observed = null;
            $this->await(function () use ($ids, &$observed) {
                $observed = DB::selectOne('SELECT r.trx_mysql_thread_id AS waiter, b.trx_mysql_thread_id AS blocker, l.lock_table AS locked_table, l.lock_index AS locked_index FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id WHERE r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=?', [$ids[1], $ids[0]]);

                return $observed !== null;
            });
            $this->assertStringContainsString('pak_cases', $observed->locked_table);
            $this->assertSame('PRIMARY', $observed->locked_index);
            touch($this->root.'/release');
            $results = array_map(fn ($worker) => $this->workerResult($worker), $workers);
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            $this->assertSame($ids, array_column($results, 'connection_id'));
            $this->assertCount(1, array_filter($results, fn ($r) => $r['success']));
            $loser = array_values(array_filter($results, fn ($r) => ! $r['success']))[0];
            $this->assertSame('VALIDATION_FAILED', $loser['issue_code']);
            fwrite(STDOUT, "\nCONVERSION_RACE ".json_encode(['repetition' => $repetition, 'observer' => $observer,
                'wait' => $observed, 'workers' => $results])."\n");
            $this->committedState($request, $marker);
        } finally {
            touch($this->root.'/release');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }
    }

    public function test_live_retry_rebuilds_after_separately_committed_evidence_change(): void
    {
        $request = $this->request();
        $original = get_object_vars($request);
        $marker = $this->rule->fresh()->getRawOriginal('first_used_at');
        $attempts = $discoveries = $audits = 0;
        $ruleReads = [];
        DB::connection()->beforeStartingTransaction(function () use (&$attempts) {
            $attempts++;
        });
        DB::listen(function ($query) use (&$discoveries, &$ruleReads, &$attempts) {
            if (DB::transactionLevel() === 0 && str_starts_with($query->sql, 'select `person_id` from `pak_cases`')) {
                $discoveries++;
            }
            if (DB::transactionLevel() === 1 && str_contains($query->sql, 'from `performance_predicate_rules`') && str_contains($query->sql, '`predicate_code` =')) {
                $ruleReads[$attempts] = ($ruleReads[$attempts] ?? 0) + 1;
            }
        });
        PakEvent::created(function ($event) use (&$audits) {
            if ($event->event_code === 'CONVERSION_RUN_SAVED') {
                $audits++;
                $this->assertSame(1, PakConversionRun::count());
                $this->assertSame(4, PakConversionPeriod::count());
                $this->assertSame($this->case->lock_version + 1, $this->case->fresh()->lock_version);
                $error = new \PDOException('Synthetic exact retry fault on real MariaDB transaction');
                $error->errorInfo = ['40001', 1213, 'Synthetic'];
                throw new QueryException('mysql', 'synthetic', [], $error);
            }
        });
        config(['database.connections.conversion_competitor' => DB::connection()->getConfig()]);
        $other = DB::connection('conversion_competitor');
        $ids = [(int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $other->selectOne('SELECT CONNECTION_ID() AS id')->id];
        $this->assertNotSame($ids[0], $ids[1]);
        $changed = false;
        $dispatcher = DB::connection()->getEventDispatcher();
        $dispatcher->listen(TransactionRolledBack::class, function () use (&$changed, $other) {
            if ($changed) {
                return;
            }
            $changed = true;
            $this->assertEmptyState();
            $other->transaction(fn () => $other->table('employee_position_histories')->where('id', $this->history->id)
                ->update(['source_ref' => 'Synthetic separately committed changed evidence']));
        });
        try {
            app(ConversionService::class)->saveInitial($this->actor, $request);
            $this->fail('Stale client expectations accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('snapshot', $error->errors());
            $this->assertSame(2, $attempts);
            $this->assertSame(2, $discoveries);
            $this->assertSame([1, 2], array_keys($ruleReads));
            $this->assertGreaterThan(0, $ruleReads[1]);
            $this->assertSame($ruleReads[1], $ruleReads[2]);
            $this->assertSame(1, $audits);
            $this->assertTrue($changed);
            $this->assertSame($original, get_object_vars($request));
            $this->assertEmptyState();
            $this->assertSame($marker, $this->rule->fresh()->getRawOriginal('first_used_at'));
            DB::purge();
            $this->assertEmptyState();
            $this->assertSame('Synthetic separately committed changed evidence', $this->history->fresh()->source_ref);
            fwrite(STDOUT, "\nCONVERSION_FRESH_RETRY ".json_encode(['connections' => $ids, 'attempts' => $attempts,
                'discoveries' => $discoveries, 'rule_reads' => $ruleReads, 'outcome' => 'STALE_REJECTION',
                'transaction_level' => DB::transactionLevel(), 'pdo_active' => DB::connection()->getPdo()->inTransaction()])."\n");
        } finally {
            $dispatcher->forget(TransactionRolledBack::class);
            $other->table('employee_position_histories')->where('id', $this->history->id)->update(['source_ref' => $this->history->source_ref]);
            DB::purge('conversion_competitor');
        }
    }

    private function assertEmptyState(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertCount(0, app('db.transactions')->getPendingTransactions());
        $this->assertCount(0, app('db.transactions')->getCommittedTransactions());
        $this->assertSame(0, PakConversionRun::count());
        $this->assertSame(0, PakConversionPeriod::count());
        $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->assertSame($this->case->lock_version, $this->case->fresh()->lock_version);
    }
}
