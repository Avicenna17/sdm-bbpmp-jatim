<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionPreview;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\DTOs\NormalizedSkpRecord;
use App\Domain\Pak\Services\ConversionManifestBuilder;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\PakCaseService;
use App\Domain\Pak\Services\PerformanceEvaluationService;
use App\Domain\Pak\Services\SkpFingerprintSerializer;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\Support\Pak\SyntheticSkpSourceAdapter;
use Tests\TestCase;

class ConversionCaseRefreshConcurrencyTest extends TestCase
{
    private PerformanceEvaluation $previous;

    private string $root;

    private User $actor;

    private PakCase $case;

    private PakRuleSet $rule;

    private EmployeePositionHistory $history;

    private FunctionalLevel $level;

    private function fixture(): void
    {

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
        $this->assertSame('root', $config['username']);
        $this->assertSame('', (string) $config['password']);
        $identity = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS db, CONNECTION_ID() AS connection_id');
        $this->assertSame('pak_02a1_test', $identity->db);
        $this->assertStringStartsWith('10.5.11-MariaDB', $identity->version);
        fwrite(STDOUT, "\nCONVERSION_SERVER ".json_encode($identity)."\n");

        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->root = storage_path('framework/testing/conversion-concurrency-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0700, true);
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $this->root, 'visibility' => 'private', 'throw' => false]]);
        Storage::forgetDisk('local');
        $this->actor = User::factory()->create(['is_active' => true]);
        foreach (['pak.access', 'pak.skp.manage', 'pak.case.create', 'pak.case.update', ...ConversionService::PERMISSIONS] as $permission) {
            $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        // Entirely synthetic integration data. This is NOT Nurmaulida documentary evidence.
        $person = Person::factory()->create(['canonical_name' => 'Synthetic SKP Conversion Subject', 'nip' => '001234567890123456']);
        $category = FunctionalCategory::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        $this->level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'PREVIEW', 'name' => 'Synthetic']);
        $position = FunctionalPosition::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        FunctionalPositionLevel::create(['functional_position_id' => $position->id, 'functional_level_id' => $this->level->id]);
        $this->rule = PakRuleSet::create(['code' => 'PREVIEW', 'version' => '1', 'name' => 'Synthetic', 'valid_from' => '2023-01-01', 'valid_until' => '2026-12-31', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        PerformancePredicateRule::create(['pak_rule_set_id' => $this->rule->id, 'predicate_code' => 'BAIK', 'predicate_name' => 'Synthetic', 'predicate_factor' => '1']);
        AkCoefficientRule::create(['pak_rule_set_id' => $this->rule->id, 'functional_level_id' => $this->level->id, 'annual_coefficient' => '25']);
        PerformancePredicateRule::create(['pak_rule_set_id' => $this->rule->id, 'predicate_code' => 'SANGAT_BAIK', 'predicate_name' => 'Synthetic very good', 'predicate_factor' => '1.5']);
        $this->history = EmployeePositionHistory::create(['person_id' => $person->id, 'position_name' => 'Synthetic position', 'position_type' => 'JF', 'functional_position_id' => $position->id, 'functional_level_id' => $this->level->id,
            'tmt_start' => '2023-01-01', 'sk_number' => 'SYNTHETIC-SK-001', 'sk_date' => '2022-12-15', 'source' => 'FIXTURE', 'source_ref' => 'Synthetic documentary reference', 'verified_by' => $this->actor->id, 'verified_at' => now(), 'created_by' => $this->actor->id]);
        $this->case = app(PakCaseService::class)->create($this->actor, [
            'person_id' => $person->id, 'pak_rule_set_id' => $this->rule->id, 'purpose_type' => 'KP',
            'period_start' => '2023-01-01', 'period_end' => '2026-12-31', 'employee_data_as_of' => '2026-01-01']);
        $this->case->refresh();
        $this->assertNotNull($this->rule->fresh()->first_used_at);
        foreach ([2023, 2024, 2025, 2026] as $year) {
            $source = $this->source($year);
            $record = $this->record($source);
            $evaluation = app(PerformanceEvaluationService::class)->createInitial($this->actor, $record);
            if ($year === 2026) {
                $this->previous = $evaluation;
            }
        }
    }

    private function source(int $year = 2025, string $predicate = 'BAIK'): array
    {
        return ['nip' => '001234567890123456', 'source_kind' => 'OFFICIAL', 'evaluation_type' => 'ANNUAL',
            'performance_year' => $year, 'source_reference' => 'SYNTHETIC-CROSS-WRITER-'.$year,
            'source_record_identifier' => 'ANNUAL-'.$year, 'predicate_code' => $predicate,
            'predicate_label_original' => $predicate, 'coverage_start' => $year.'-01-01', 'coverage_end' => $year.'-12-31'];
    }

    private function stream(array $source): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, json_encode([$source], JSON_THROW_ON_ERROR));
        rewind($stream);

        return $stream;
    }

    private function record(array $source): NormalizedSkpRecord
    {
        $stream = $this->stream($source);
        try {
            $row = iterator_to_array((new SyntheticSkpSourceAdapter)->read($stream))[0];

            return app(SkpFingerprintSerializer::class)->normalize($row, $this->case->person_id);
        } finally {
            fclose($stream);
        }
    }

    private function intent(): array
    {
        $preview = app(ConversionService::class)->preview($this->actor,
            new ConversionPreviewRequest($this->case->id, '2023-11-01', '2026-10-31'));
        $this->assertSame([], $preview->issues);
        $this->assertSame('75', (string) $preview->exact_total->getNumerator());
        $this->assertSame('1', (string) $preview->exact_total->getDenominator());
        $request = ConversionSaveRequest::from(['pak_case_id' => $this->case->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint]);

        return [$preview, $request];
    }

    public static function mutationsAndOrders(): array
    {
        return ['period_refresh_first' => ['period', true], 'period_conversion_first' => ['period', false],
            'rule_refresh_first' => ['rule', true], 'rule_conversion_first' => ['rule', false]];
    }

    #[DataProvider('mutationsAndOrders')]
    public function test_case_refresh_and_initial_save_serialize_on_case(string $mutation, bool $refreshFirst): void
    {
        $this->fixture();
        [$preview, $request] = $this->intent();
        $caseBefore = $this->case->getAttributes();
        $snapshotBefore = $this->case->employeeSnapshot->getAttributes();
        $ruleBefore = $this->rule->fresh()->getAttributes();
        $auditBefore = $this->rows('pak_events');
        $input = ['person_id' => $this->case->person_id, 'pak_rule_set_id' => $this->rule->id,
            'purpose_type' => 'KP', 'period_start' => '2023-01-01', 'period_end' => '2026-12-31',
            'employee_data_as_of' => '2026-01-01'];
        $alternative = null;
        if ($mutation === 'period') {
            $input['period_start'] = '2023-02-01';
        } else {
            $alternative = PakRuleSet::create(['code' => 'ALTERNATIVE', 'version' => '1', 'name' => 'Synthetic alternative',
                'valid_from' => '2023-01-01', 'valid_until' => '2026-12-31', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
            PerformancePredicateRule::create(['pak_rule_set_id' => $alternative->id, 'predicate_code' => 'BAIK', 'predicate_name' => 'Synthetic', 'predicate_factor' => '1']);
            AkCoefficientRule::create(['pak_rule_set_id' => $alternative->id, 'functional_level_id' => $this->level->id, 'annual_coefficient' => '25']);
            $input['pak_rule_set_id'] = $alternative->id;
            $this->assertNull($alternative->first_used_at);
        }
        $hash = app(PakCaseService::class)->preview($this->actor, $input)->fingerprint();
        $alternativeBefore = $alternative?->fresh()->getAttributes();
        $refresh = ['operation' => 'case_refresh', 'case_id' => $this->case->id, 'case' => $input,
            'expected_version' => $request->expected_case_lock_version, 'preview_hash' => $hash,
            'reason' => 'Synthetic explicit case refresh'];
        $conversion = ['operation' => 'conversion', 'request' => get_object_vars($request)];
        $results = $this->race($refreshFirst ? [$refresh, $conversion] : [$conversion, $refresh], 'pak_cases');
        $writer = $results[$refreshFirst ? 0 : 1];
        $save = $results[$refreshFirst ? 1 : 0];
        $this->assertSame($refreshFirst, $writer['success']);
        $this->assertSame(! $refreshFirst, $save['success']);
        $loser = $refreshFirst ? $save : $writer;
        $this->assertSame(['case'], $loser['validation_fields']);
        if ($refreshFirst) {
            $this->assertSame('VALIDATION_FAILED', $save['issue_code']);
            $this->assertArrayNotHasKey('run_id', $save);
            $this->assertSame($this->case->id, $writer['case_id']);
        }
        $current = $this->case->fresh();
        $this->assertSame($request->expected_case_lock_version + 1, $current->lock_version);
        $this->assertSame($refreshFirst ? $input['period_start'] : '2023-01-01', $current->period_start->format('Y-m-d'));
        $this->assertSame($refreshFirst ? $input['pak_rule_set_id'] : $this->rule->id, $current->pak_rule_set_id);
        $this->assertSame('DRAFT', $current->status);
        $this->assertNull($current->finalized_at);
        $this->assertSame($ruleBefore, $this->rule->fresh()->getAttributes());
        if ($alternative) {
            if ($refreshFirst) {
                $this->assertNotNull($alternative->fresh()->first_used_at);
                foreach ($alternativeBefore as $key => $value) {
                    if (! in_array($key, ['first_used_at', 'updated_at'], true)) {
                        $this->assertSame($value, $alternative->fresh()->getRawOriginal($key));
                    }
                }
            } else {
                $this->assertSame($alternativeBefore, $alternative->fresh()->getAttributes());
            }
        }
        $this->assertSame($auditBefore, array_slice($this->rows('pak_events'), 0, count($auditBefore)));
        $this->assertSame($refreshFirst ? 1 : 0, $this->events('PAK_SNAPSHOT_REFRESHED'));
        $this->assertSame($refreshFirst ? 0 : 1, $this->events('CONVERSION_RUN_SAVED'));
        $this->assertSame(0, $this->events('CONVERSION_RUN_REPLACED'));
        $this->assertSame(0, $this->events('CONVERSION_RUN_REVIEW_ASSESSED'));
        $this->assertSame($refreshFirst ? 0 : 1, PakConversionRun::count());
        $this->assertSame($refreshFirst ? 0 : 4, PakConversionPeriod::count());
        if ($refreshFirst) {
            $this->assertSame($this->case->id, PakEvent::where('event_code', 'PAK_SNAPSHOT_REFRESHED')->sole()->subject_id);
            $this->assertSame($hash, app(PakCaseService::class)->preview($this->actor, $input)->fingerprint());
        } else {
            $this->assertSame($snapshotBefore, $current->employeeSnapshot->getAttributes());
            $run = $this->assertSaved($preview, $request);
            $this->assertSame($save['run_id'], $run->id);
        }
        $allowed = ['lock_version', 'updated_by', 'updated_at'];
        if ($refreshFirst) {
            $allowed[] = $mutation === 'period' ? 'period_start' : 'pak_rule_set_id';
        }
        foreach ($caseBefore as $field => $value) {
            if (! in_array($field, $allowed, true)) {
                $this->assertSame($value, $current->getRawOriginal($field), $field);
            }
        }
        fwrite(STDOUT, "\nCASE_REFRESH_SAVE_FINAL ".json_encode(['mutation' => $mutation, 'refresh_first' => $refreshFirst,
            'case_version_before' => $request->expected_case_lock_version, 'case_version_after' => $current->lock_version,
            'period_before' => '2023-01-01', 'period_after' => $current->period_start->format('Y-m-d'),
            'rule_before' => $this->rule->id, 'rule_after' => $current->pak_rule_set_id,
            'loser_validation_fields' => $loser['validation_fields'], 'runs' => PakConversionRun::count(),
            'saved_audits' => $this->events('CONVERSION_RUN_SAVED'), 'refresh_audits' => $this->events('PAK_SNAPSHOT_REFRESHED')])."\n");
    }

    private function race(array $operations, string $resource): array
    {
        $workers = [];
        foreach ($operations as $index => $operation) {
            $input = $operation + ['db' => DB::connection()->getConfig(), 'root' => $this->root,
                'actor' => $this->actor->id, 'worker' => (string) $index,
                'barrier' => $index === 0 ? 'hold' : 'none', 'lock_table' => $resource];
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/Pak/concurrency-worker.php')], base_path(),
                ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => 'bootstrap/cache/skp-test-unused-config.php'],
                json_encode($input, JSON_THROW_ON_ERROR), 25);
        }
        try {
            $workers[0]->start();
            $this->await(fn () => is_file($this->root.'/0.held'), $workers);
            $workers[1]->start();
            $this->await(fn () => is_file($this->root.'/1.connection'), $workers);
            $ids = [(int) file_get_contents($this->root.'/0.connection'), (int) file_get_contents($this->root.'/1.connection')];
            $this->assertNotSame($ids[0], $ids[1]);
            DB::purge();
            $observer = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertNotContains($observer, $ids);
            $wait = null;
            $this->await(function () use ($ids, &$wait) {
                $wait = DB::selectOne('SELECT r.trx_mysql_thread_id AS waiter, b.trx_mysql_thread_id AS blocker, l.lock_table AS locked_table, l.lock_index AS locked_index FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id WHERE r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=?', [$ids[1], $ids[0]]);

                return $wait !== null;
            }, $workers);
            $this->assertSame('`pak_02a1_test`.`'.$resource.'`', $wait->locked_table);
            $this->assertSame($ids[1], (int) $wait->waiter);
            $this->assertSame($ids[0], (int) $wait->blocker);
            $this->assertTrue($workers[0]->isRunning());
            $this->assertTrue($workers[1]->isRunning());
            $this->assertSame('PRIMARY', $wait->locked_index);
            touch($this->root.'/release');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), 'Worker failed; private stderr omitted.');
                $this->assertSame('', $worker->getErrorOutput(), 'Private stderr omitted.');
                $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(0, $result['transaction_level']);
                $this->assertFalse($result['pdo_active']);
                $this->assertSame(1, $result['attempts'], 'Normal serialization must not retry or deadlock.');
                $this->assertSame([], $result['diagnostics'], 'Unexpected database failure: stop for production review.');
                $results[] = $result;
            }
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            $this->assertNotContains(getmypid(), array_column($results, 'pid'));
            $this->assertSame($ids, array_column($results, 'connection_id'));
            DB::purge();
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->await(fn () => (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX')->n === 0, []);
            fwrite(STDOUT, "\nCLOSURE_GATE_SERIALIZATION ".json_encode(['resource' => $resource,
                'observer' => $observer, 'wait' => $wait, 'workers' => $results, 'remaining_transactions' => 0])."\n");

            return $results;
        } finally {
            touch($this->root.'/release');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
                $this->assertFalse($worker->isRunning());
            }
        }
    }

    private function await(callable $condition, array $workers): void
    {
        $deadline = microtime(true) + 12;
        while (! $condition()) {
            foreach ($workers as $worker) {
                if ($worker->isStarted()) {
                    $worker->checkTimeout();
                    $this->assertTrue($worker->isRunning(), 'Worker exited before observed lock wait.');
                }
            }
            if (microtime(true) > $deadline) {
                $this->fail('Person barrier/lock-wait timeout.');
            }
            usleep(200000);
        }
    }

    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function events(string $code): int
    {
        return PakEvent::where('event_code', $code)->count();
    }

    private function assertSaved(ConversionPreview $preview, ConversionSaveRequest $request): PakConversionRun
    {
        $run = PakConversionRun::sole();
        $this->assertSame(1, $run->revision_number);
        $this->assertSame('PREVIEW', $run->status->value);
        $this->assertSame($this->case->id, $run->active_case_id);
        $this->assertSame($this->rule->id, $run->pak_rule_set_id);
        $this->assertNull($run->predecessor_run_id);
        $this->assertNull($run->review_assessment);
        $this->assertNull($run->review_assessed_by);
        $this->assertNull($run->review_assessed_at);
        $this->assertNull($run->superseded_at);
        $this->assertSame($request->expected_case_lock_version + 1, $run->case_lock_version);
        $this->assertSame('75/1', $run->total_numerator.'/'.$run->total_denominator);
        $this->assertSame($preview->dependency_fingerprint, $run->dependency_fingerprint);
        $this->assertSame($preview->snapshot_fingerprint, $run->snapshot_fingerprint);
        $this->assertSame(ConversionManifestBuilder::canonical($preview->dependencies->groups), ConversionManifestBuilder::canonical($run->dependency_manifest));
        $this->assertSame(['25/6', '25/1', '25/1', '125/6'], $run->periods()->orderBy('line_number')->get()->map(fn ($p) => $p->result_numerator.'/'.$p->result_denominator)->all());
        $this->assertSame($run->id, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->sole()->subject_id);

        return $run;
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }
}
