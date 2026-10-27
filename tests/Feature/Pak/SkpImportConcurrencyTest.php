<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Services\EmployeeHistoryService;
use App\Domain\Pak\Services\PerformanceEvaluationService;
use App\Domain\Pak\Services\SkpFingerprintSerializer;
use App\Domain\Pak\Services\SkpImportPreparationService;
use App\Models\EmployeePositionHistory;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use App\Models\ImportBatch;
use App\Models\PakCase;
use App\Models\PakConversionRun;
use App\Models\PakEvent;
use App\Models\PakSkpImportBatch;
use App\Models\PerformanceEvaluation;
use App\Models\Person;
use App\Models\PersonnelSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\Support\Pak\DukCoordinationFixtures;
use Tests\Support\Pak\SyntheticSkpSourceAdapter;
use Tests\TestCase;

class SkpImportConcurrencyTest extends TestCase
{
    use DukCoordinationFixtures;

    private string $root;

    private User $actor;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PAK_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Opt in with PAK_CONCURRENCY=1 and disposable MySQL configuration.');
        }
        $config = DB::connection()->getConfig();
        $this->assertSame('mysql', $config['driver']);
        $this->assertSame('pak_02a1_test', $config['database']);
        $this->assertSame('pak_02a1_test', getenv('PAK_DISPOSABLE_DB'));
        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertNotSame(3306, (int) $config['port'], 'Never run against the local application server.');
        $this->assertSame((int) getenv('PAK_CONCURRENCY_PORT'), (int) $config['port']);
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->root = storage_path('framework/testing/pak-concurrency-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0700, true);
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $this->root, 'visibility' => 'private', 'throw' => false]]);
        Storage::forgetDisk('local');
        $this->actor = User::factory()->create(['is_active' => true]);
        foreach (['pak.access', 'pak.skp.manage'] as $permission) {
            $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->person = Person::factory()->create(['nip' => '001234567890123456']);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    private function source(string $predicate = 'BAIK'): array
    {
        return ['nip' => $this->person->nip, 'source_kind' => 'OFFICIAL', 'evaluation_type' => 'ANNUAL',
            'performance_year' => 2025, 'source_reference' => 'SYNTHETIC-CONCURRENCY', 'source_record_identifier' => 'RECORD-001',
            'predicate_code' => $predicate, 'predicate_label_original' => $predicate,
            'coverage_start' => '2025-01-01', 'coverage_end' => '2025-12-31'];
    }

    private function prepare(string $predicate = 'BAIK'): PakSkpImportBatch
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, json_encode([$this->source($predicate)], JSON_THROW_ON_ERROR));
        rewind($stream);
        try {
            return app(SkpImportPreparationService::class)->prepare($this->actor, new SyntheticSkpSourceAdapter, $stream, 'synthetic.json', 'local');
        } finally {
            fclose($stream);
        }
    }

    private function initial(): PerformanceEvaluation
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, json_encode([$this->source()], JSON_THROW_ON_ERROR));
        rewind($stream);
        $source = iterator_to_array((new SyntheticSkpSourceAdapter)->read($stream))[0];
        fclose($stream);

        return app(PerformanceEvaluationService::class)->createInitial($this->actor, app(SkpFingerprintSerializer::class)->normalize($source, $this->person->id));
    }

    private function commitInput(PakSkpImportBatch $batch): array
    {
        return ['operation' => 'commit', 'batch_id' => $batch->id];
    }

    private function correctionInput(PerformanceEvaluation $previous, string $predicate): array
    {
        $evidence = $this->prepare($predicate)->rows()->sole();

        return ['operation' => 'correct', 'predecessor' => $previous->id, 'source' => $this->source($predicate),
            'person' => $this->person->id, 'source_row' => $evidence->id];
    }

    private function await(callable $condition): void
    {
        $deadline = microtime(true) + 12;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $this->fail('Test barrier/lock-wait timeout.');
            }
            // InnoDB metadata snapshots need an interval; tight polling can keep stale lock metadata.
            usleep(200000);
        }
    }

    private function pair(array $first, array $second, string $barrier, string $table = 'performance_evaluations'): array
    {
        // Person coordination now serializes before INSERT; wait on the actual mutex.
        if ($barrier === 'create') {
            $barrier = 'hold';
            $table = 'people';
        }
        $workers = [];
        foreach ([$first, $second] as $index => $operation) {
            $input = $operation + ['db' => DB::connection()->getConfig(), 'root' => $this->root,
                'actor' => $this->actor->id, 'worker' => (string) $index, 'lock_table' => $table,
                'barrier' => $barrier === 'create' ? 'create' : ($index === 0 ? 'hold' : 'none')];
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/Pak/concurrency-worker.php')], base_path(),
                ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => 'bootstrap/cache/skp-test-unused-config.php'], json_encode($input, JSON_THROW_ON_ERROR), 25);
        }
        try {
            $workers[0]->start();
            $this->await(fn () => is_file($this->root.'/0.held'));
            $workers[1]->start();
            $this->await(fn () => is_file($this->root.'/1.connection'));
            $ids = [(int) file_get_contents($this->root.'/0.connection'), (int) file_get_contents($this->root.'/1.connection')];
            $this->assertNotSame($ids[0], $ids[1]);
            if ($barrier === 'independent') {
                $this->await(fn () => ! $workers[1]->isRunning());
                $this->assertTrue($workers[0]->isRunning(), 'Unrelated person completed while first still holds lock');
            } elseif ($barrier === 'create') {
                $this->await(fn () => is_file($this->root.'/1.held'));
            } else {
                // Observe a real InnoDB lock wait between the independent workers before release.
                $this->await(function () use ($ids) {
                    $waits = DB::select('SELECT r.trx_mysql_thread_id AS waiter, b.trx_mysql_thread_id AS blocker FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id');
                    foreach ($waits as $wait) {
                        if ((int) $wait->waiter === $ids[1] && (int) $wait->blocker === $ids[0]) {
                            fwrite(STDOUT, "\nOBSERVED_LOCK_WAIT ".json_encode($wait)."\n");

                            return true;
                        }
                    }

                    return false;
                });
            }
            touch($this->root.'/release');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), 'Worker failed (private stderr intentionally omitted).');
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            DB::purge(); // Final assertions use a fresh parent connection/snapshot.
            fwrite(STDOUT, "\nCONCURRENCY ".json_encode(['version' => DB::selectOne('SELECT VERSION() AS v')->v, 'results' => $results], JSON_THROW_ON_ERROR)."\n");

            return $results;
        } finally {
            touch($this->root.'/release');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }
    }

    private function coordinationPermissions(): void
    {
        foreach (['pak.employee.manage', 'pak.employee.view', 'pak.case.create', 'pak.master.manage', 'import.commit', 'period.revise'] as $name) {
            $this->actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
    }

    private function historyInput(?Person $person = null): array
    {
        return ['operation' => 'history', 'person' => ($person ?? $this->person)->id,
            'history' => ['position_name' => 'Synthetic', 'position_type' => 'PELAKSANA', 'tmt_start' => '2020-01-01', 'source_ref' => 'SYNTHETIC']];
    }

    public static function coordinationPairs(): array
    {
        $cases = [];
        foreach (['initial', 'correct', 'duk'] as $kind) {
            foreach ([false, true] as $historyFirst) {
                $cases[$kind.'-'.(int) $historyFirst] = [$kind, $historyFirst];
            }
        }

        return $cases;
    }

    #[DataProvider('coordinationPairs')]
    public function test_history_serializes_with_evaluation_and_duk_writers(string $kind, bool $historyFirst): void
    {
        $this->coordinationPermissions();
        $other = match ($kind) {
            'initial' => ['operation' => 'initial', 'person' => $this->person->id, 'source' => $this->source()],
            'correct' => $this->correctionInput($this->initial(), 'CUKUP'),
            'duk' => ['operation' => 'duk', 'batch_id' => $this->preparedDuk($this->actor, [$this->dukPersonRow($this->person->person_key, $this->person->nip, 'Updated')])->id],
        };
        $pair = [$this->historyInput(), $other];
        if (! $historyFirst) {
            $pair = array_reverse($pair);
        }
        $results = $this->pair($pair[0], $pair[1], 'hold', 'people');
        $this->assertSame([true, true], array_column($results, 'success'));
        $this->assertSame(1, EmployeePositionHistory::count());
        $this->assertSame(1, PakEvent::where('event_code', 'HISTORY_VERIFIED')->count());
        if ($kind === 'correct') {
            $this->assertSame(2, PerformanceEvaluation::count());
            $this->assertSame(1, PakEvent::where('event_code', 'PERFORMANCE_EVALUATION_CORRECTED')->count());
        }
        if ($kind !== 'duk') {
            $this->assertSame(1, PerformanceEvaluation::where('status', 'CURRENT')->count());
        } else {
            $this->assertSame('committed', ImportBatch::findOrFail($other['batch_id'])->status);
            $this->assertSame('Updated', $this->person->fresh()->canonical_name);
        }
        $this->assertSame(0, PakConversionRun::count());
        $this->assertSame(0, PakEvent::where('event_code', 'like', 'CONVERSION_RUN_%')->count());
    }

    public function test_reversed_duk_workbooks_coordinate_existing_people_and_keep_new_identities(): void
    {
        $this->coordinationPermissions();
        $other = Person::factory()->create();
        $a = $this->dukPersonRow($this->person->person_key, $this->person->nip, 'A');
        $b = $this->dukPersonRow($other->person_key, $other->nip, 'B');
        $new = $this->dukPersonRow(hash('sha256', 'new-person'), null, 'New');
        $first = $this->preparedDuk($this->actor, [$b, $a, $new], '2026-09-01');
        $second = $this->preparedDuk($this->actor, [$a, $b], '2026-10-01');
        $results = $this->pair(['operation' => 'duk', 'batch_id' => $first->id], ['operation' => 'duk', 'batch_id' => $second->id], 'hold', 'people');
        $this->assertSame([true, true], array_column($results, 'success'));
        $this->assertSame(3, Person::count());
        $this->assertSame(5, PersonnelSnapshot::count());
        $this->assertSame(['B', 'A', 'New'], PersonnelSnapshot::where('import_batch_id', $first->id)->orderBy('id')->pluck('name_at_period')->all());
        $this->assertSame(['A', 'B'], PersonnelSnapshot::where('import_batch_id', $second->id)->orderBy('id')->pluck('name_at_period')->all());
        $this->assertSame('committed', $first->fresh()->status);
        $this->assertSame('committed', $second->fresh()->status);
        $this->assertSame(0, PakEvent::count());
    }

    public static function dukReaders(): array
    {
        return [['commit', false], ['commit', true], ['case', false], ['case', true]];
    }

    #[DataProvider('dukReaders')]
    public function test_duk_serializes_with_skp_commit_or_case_capture(string $kind, bool $dukFirst): void
    {
        $this->coordinationPermissions();
        $originalName = $this->person->canonical_name;
        $duk = ['operation' => 'duk', 'batch_id' => $this->preparedDuk($this->actor, [$this->dukPersonRow($this->person->person_key, $this->person->nip, 'Updated')])->id];
        $other = $kind === 'commit' ? $this->commitInput($this->prepare()) : ['operation' => 'case', 'case' => [
            'person_id' => $this->person->id, 'purpose_type' => 'KP', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'employee_data_as_of' => '2025-12-31', 'pak_rule_set_id' => null]];
        $pair = $dukFirst ? [$duk, $other] : [$other, $duk];
        $results = $this->pair($pair[0], $pair[1], 'hold', 'people');
        $this->assertSame([true, true], array_column($results, 'success'));
        $this->assertSame('committed', ImportBatch::findOrFail($duk['batch_id'])->status);
        if ($kind === 'commit') {
            $this->committed(PakSkpImportBatch::findOrFail($other['batch_id']));
            $this->assertSame(1, PerformanceEvaluation::count());
            $this->assertSame(1, PakEvent::where('event_code', 'SKP_IMPORT_COMMITTED')->count());
        } else {
            $case = PakCase::sole();
            $this->assertSame($dukFirst ? 'Updated' : $originalName, $case->employeeSnapshot->name);
            $this->assertSame(1, PakEvent::where('event_code', 'PAK_CASE_CREATED')->count());
        }
        $this->assertSame(0, PakConversionRun::count());
    }

    public static function masterReaders(): array
    {
        return [['history', false], ['history', true], ['case', false], ['case', true]];
    }

    #[DataProvider('masterReaders')]
    public function test_shared_master_writer_serializes_with_history_and_capture(string $kind, bool $masterFirst): void
    {
        $this->coordinationPermissions();
        $category = FunctionalCategory::create(['code' => 'SYNTH', 'name' => 'Synthetic', 'sort_order' => 1, 'is_active' => true]);
        $level = FunctionalLevel::create(['code' => 'SYNTH', 'name' => 'Original', 'functional_category_id' => $category->id, 'sort_order' => 1, 'is_active' => true]);
        $position = FunctionalPosition::create(['code' => 'SYNTH', 'name' => 'Synthetic', 'is_active' => true]);
        FunctionalPositionLevel::create(['functional_position_id' => $position->id, 'functional_level_id' => $level->id, 'is_active' => true]);
        $history = $this->historyInput();
        $history['history'] = array_replace($history['history'], ['position_type' => 'JF', 'functional_position_id' => $position->id, 'functional_level_id' => $level->id]);
        if ($kind === 'case') {
            app(EmployeeHistoryService::class)->record($this->actor, $this->person, $history['history'], true);
            $reader = ['operation' => 'case', 'case' => ['person_id' => $this->person->id, 'purpose_type' => 'KP', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'employee_data_as_of' => '2025-12-31', 'pak_rule_set_id' => null]];
        } else {
            $reader = $history;
        }
        $master = ['operation' => 'master', 'type' => 'level', 'master_id' => $level->id, 'master' => ['code' => 'SYNTH', 'name' => 'Updated', 'functional_category_id' => $category->id, 'sort_order' => 1, 'is_active' => true]];
        $pair = $masterFirst ? [$master, $reader] : [$reader, $master];
        $results = $this->pair($pair[0], $pair[1], 'hold', 'functional_levels');
        $this->assertSame([true, true], array_column($results, 'success'));
        $this->assertSame('Updated', $level->fresh()->name);
        $this->assertSame(1, EmployeePositionHistory::count());
        $this->assertSame(1, PakEvent::where('event_code', 'MASTER_SAVED')->count());
        if ($kind === 'case') {
            $this->assertSame($masterFirst ? 'Updated' : 'Original', PakCase::sole()->employeeSnapshot->functional_level_name);
        }
    }

    public function test_different_people_without_shared_master_are_independent(): void
    {
        $this->coordinationPermissions();
        $other = Person::factory()->create();
        $results = $this->pair($this->historyInput(), $this->historyInput($other), 'independent', 'people');
        $this->assertSame([true, true], array_column($results, 'success'));
        $this->assertSame(2, EmployeePositionHistory::count());
        $this->assertSame(2, PakEvent::where('event_code', 'HISTORY_VERIFIED')->count());
    }

    private function committed(PakSkpImportBatch $batch): void
    {
        $this->assertSame('COMMITTED', $batch->fresh()->status->value);
        $this->assertSame(0, $batch->rows()->whereIn('processing_status', ['PENDING', 'READY'])->count());
        $this->assertSame(1, PakEvent::where('subject_type', 'PakSkpImportBatch')->where('subject_id', $batch->id)->where('event_code', 'SKP_IMPORT_COMMITTED')->count());
    }

    public static function contents(): array
    {
        return [['BAIK', 'DUPLICATE'], ['CUKUP', 'CONFLICT']];
    }

    #[DataProvider('contents')]
    public function test_concurrent_imports(string $predicate, string $loser): void
    {
        $a = $this->prepare();
        $b = $this->prepare($predicate);
        $results = $this->pair($this->commitInput($a), $this->commitInput($b), 'create');
        $this->assertSame([true, true], array_column($results, 'success'));
        $this->committed($a);
        $this->committed($b);
        $this->assertSame(1, PerformanceEvaluation::count());
        $current = PerformanceEvaluation::sole();
        $this->assertSame('CURRENT', $current->status->value);
        $rows = [$a->rows()->sole(), $b->rows()->sole()];
        $outcomes = array_map(fn ($row) => $row->processing_status->value, $rows);
        sort($outcomes);
        $expected = ['IMPORTED', $loser];
        sort($expected);
        $this->assertSame($expected, $outcomes);
        foreach ($rows as $row) {
            $this->assertSame($row->processing_status->value === 'CONFLICT' ? null : $current->id, $row->performance_evaluation_id);
        }
    }

    public function test_double_commit(): void
    {
        $batch = $this->prepare();
        $results = $this->pair($this->commitInput($batch), $this->commitInput($batch), 'hold', 'pak_skp_import_batches');
        $this->assertSame([true, true], array_column($results, 'success'));
        foreach (['committed_by', 'committed_at', 'summary', 'rows'] as $key) {
            $this->assertSame($results[0][$key], $results[1][$key]);
        }
        $this->committed($batch);
        $this->assertSame(1, PerformanceEvaluation::count());
    }

    public function test_evaluation_link_guard_after_snapshot_precedes_competitor_commit(): void
    {
        $batch = $this->prepare();
        $connection = DB::connection();
        $name = DB::getDefaultConnection();
        config(['database.connections.skp_competitor' => array_diff_key($connection->getConfig(), ['name' => true])]);
        $connection->beginTransaction();
        try {
            $this->assertSame(0, PerformanceEvaluation::count()); // Establish REPEATABLE-READ snapshot.
            $parentId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
            DB::setDefaultConnection('skp_competitor');
            $competitorId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertNotSame($parentId, $competitorId);
            $evaluation = $this->initial(); // Production service, independent connection commits.
            DB::setDefaultConnection($name);

            $current = PerformanceEvaluation::where('current_source_identity_fingerprint', $evaluation->source_identity_fingerprint)->lockForUpdate()->sole();
            $row = $batch->rows()->sole();
            $row->performance_evaluation_id = $current->id;
            fwrite(STDOUT, "\nVISIBILITY_DIAGNOSTIC ".json_encode(['isolation' => $connection->selectOne('SELECT @@tx_isolation AS v')->v,
                'parent' => $parentId, 'competitor' => $competitorId, 'level' => $connection->transactionLevel(),
                'pdo_transaction' => $connection->getPdo()->inTransaction(), 'count' => $connection->table('performance_evaluations')->count(),
                'row_connection' => $row->getConnectionName(), 'evaluation_connection' => $evaluation->getConnectionName()])."\n");
            $this->assertFalse($row->performanceEvaluation()->where('person_id', $row->person_id)->exists());
            $this->assertSame($current->id, $row->performanceEvaluation()->where('person_id', $row->person_id)->sharedLock()->firstOrFail()->id);
            fwrite(STDOUT, "\nSNAPSHOT_PROOF ordinary_exists=false shared_current_read=true; distinct_connections=true\n");
            $row->processing_status = 'DUPLICATE';
            $row->save(); // Old model guard fails here despite valid current-read evaluation/person.
            $this->assertSame($current->id, $row->fresh()->performance_evaluation_id);
        } finally {
            DB::setDefaultConnection($name);
            $connection->rollBack();
            DB::purge('skp_competitor');
        }
    }

    public function test_history_validation_uses_current_state_after_an_older_repeatable_read_snapshot(): void
    {
        $this->coordinationPermissions();
        $connection = DB::connection();
        $name = DB::getDefaultConnection();
        config(['database.connections.history_competitor' => array_diff_key($connection->getConfig(), ['name' => true])]);
        $input = $this->historyInput()['history'];
        $connection->beginTransaction();
        try {
            $this->assertSame('REPEATABLE-READ', $connection->selectOne('SELECT @@tx_isolation AS v')->v);
            $this->assertSame(0, EmployeePositionHistory::count());
            $parentId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
            DB::setDefaultConnection('history_competitor');
            $this->assertNotSame($parentId, (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
            app(EmployeeHistoryService::class)->record($this->actor, $this->person, $input, true);
            DB::setDefaultConnection($name);
            $this->assertSame(0, EmployeePositionHistory::count(), 'Old snapshot deliberately remains');
            try {
                app(EmployeeHistoryService::class)->record($this->actor, $this->person, $input, true);
                $this->fail('Current overlap must reject, despite earlier empty snapshot');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('tmt_start', $e->errors());
            }
        } finally {
            DB::setDefaultConnection($name);
            $connection->rollBack();
            DB::purge('history_competitor');
        }
        $this->assertSame(1, EmployeePositionHistory::count());
        $this->assertSame(1, PakEvent::where('event_code', 'HISTORY_VERIFIED')->count());
    }

    public function test_real_current_unique_error_shape_from_concurrent_initial_creation(): void
    {
        // No absent-CURRENT lookup/gap locks: isolate the actual driver duplicate-key diagnostic.
        // createInitial intentionally does not classify/retry collision; batch orchestration owns that.
        $input = ['operation' => 'initial', 'source' => $this->source(), 'person' => $this->person->id];
        $results = $this->pair($input, $input, 'create');
        $this->assertCount(1, array_filter($results, fn ($result) => $result['success']));
        $diagnostics = array_values(array_filter(array_merge(...array_column($results, 'diagnostics')), fn ($entry) => isset($entry['sqlstate'])));
        $this->assertCount(1, $diagnostics);
        $this->assertSame('23000', $diagnostics[0]['sqlstate']);
        $this->assertSame(1062, $diagnostics[0]['vendor']);
        $this->assertSame("Duplicate entry '<redacted>' for key 'pe_current_source_uq'", $diagnostics[0]['message']);
        $this->assertTrue($diagnostics[0]['commit_retryable']);
        $this->assertFalse($diagnostics[0]['correction_retryable']);
        $this->assertSame(1, PerformanceEvaluation::count());
        $this->assertSame('CURRENT', PerformanceEvaluation::sole()->status->value);
        $this->assertSame(0, PakEvent::count());
    }

    public function test_two_corrections(): void
    {
        $previous = $this->initial();
        $results = $this->pair($this->correctionInput($previous, 'CUKUP'), $this->correctionInput($previous, 'KURANG'), 'hold');
        $this->assertSame([true, false], array_column($results, 'success'));
        $this->assertSame('PREDECESSOR_NOT_CURRENT', $results[1]['issue_code']);
        $this->chain($previous);
    }

    private function chain(PerformanceEvaluation $previous): void
    {
        $this->assertSame('SUPERSEDED', $previous->fresh()->status->value);
        $this->assertSame(1, PerformanceEvaluation::where('previous_version_id', $previous->id)->count());
        $this->assertSame(1, PerformanceEvaluation::where('status', 'CURRENT')->count());
        $this->assertSame(2, PerformanceEvaluation::count());
        $this->assertSame(1, PakEvent::where('event_code', 'PERFORMANCE_EVALUATION_CORRECTED')->count());
    }

    public static function orderings(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('orderings')]
    public function test_import_versus_correction(bool $importFirst): void
    {
        $previous = $this->initial();
        $batch = $this->prepare();
        $import = $this->commitInput($batch);
        $correction = $this->correctionInput($previous, 'CUKUP');
        $results = $this->pair($importFirst ? $import : $correction, $importFirst ? $correction : $import, 'hold');
        fwrite(STDOUT, "\nFINAL_INVARIANTS ".json_encode([
            'predecessor_status' => $previous->fresh()->status->value,
            'successors' => PerformanceEvaluation::where('previous_version_id', $previous->id)->count(),
            'current_count' => PerformanceEvaluation::where('status', 'CURRENT')->count(),
            'correction_audits' => PakEvent::where('event_code', 'PERFORMANCE_EVALUATION_CORRECTED')->count(),
            'commit_audits' => PakEvent::where('event_code', 'SKP_IMPORT_COMMITTED')->count(),
            'batch_status' => $batch->fresh()->status->value,
            'open_rows' => $batch->rows()->whereIn('processing_status', ['READY', 'PENDING'])->count(),
        ])."\n");
        $this->assertSame([true, true], array_column($results, 'success'));
        $this->chain($previous);
        $this->committed($batch);
        $row = $batch->rows()->sole();
        // A deadlock retry can reverse completion order even when correction acquired the first lock.
        $this->assertContains($row->processing_status->value, ['DUPLICATE', 'CONFLICT']);
        $this->assertSame($row->processing_status->value === 'DUPLICATE' ? $previous->id : null, $row->performance_evaluation_id);
    }
}
