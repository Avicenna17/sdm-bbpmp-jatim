<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionPreview;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\DTOs\NormalizedSkpRecord;
use App\Domain\Pak\Services\ConversionManifestBuilder;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\EmployeeHistoryService;
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
use App\Models\PerformancePredicateRule;
use App\Models\Person;
use App\Models\User;
use Brick\Math\BigRational;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\Support\Pak\SyntheticSkpSourceAdapter;
use Tests\TestCase;

class ConversionPositionHistoryConcurrencyTest extends TestCase
{
    private FunctionalLevel $newLevel;

    private AkCoefficientRule $oldCoefficient;

    private string $root;

    private User $actor;

    private PakCase $case;

    private PakRuleSet $rule;

    private EmployeePositionHistory $history;

    private FunctionalLevel $level;

    private function fixture(bool $live): void
    {
        if ($live) {
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
        } else {
            $this->assertSame('sqlite', DB::getDriverName());
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->root = storage_path('framework/testing/conversion-concurrency-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0700, true);
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $this->root, 'visibility' => 'private', 'throw' => false]]);
        Storage::forgetDisk('local');
        $this->actor = User::factory()->create(['is_active' => true]);
        foreach (['pak.access', 'pak.skp.manage', 'pak.employee.manage', ...ConversionService::PERMISSIONS] as $permission) {
            $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        // Entirely synthetic integration data. This is NOT Nurmaulida documentary evidence.
        $person = Person::factory()->create(['canonical_name' => 'Synthetic Position Conversion Subject', 'nip' => '001234567890123456']);
        $category = FunctionalCategory::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        $this->level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'PREVIEW', 'name' => 'Synthetic']);
        $position = FunctionalPosition::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        FunctionalPositionLevel::create(['functional_position_id' => $position->id, 'functional_level_id' => $this->level->id]);
        $this->rule = PakRuleSet::create(['code' => 'PREVIEW', 'version' => '1', 'name' => 'Synthetic', 'valid_from' => '2023-01-01', 'valid_until' => '2026-12-31', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        PerformancePredicateRule::create(['pak_rule_set_id' => $this->rule->id, 'predicate_code' => 'BAIK', 'predicate_name' => 'Synthetic', 'predicate_factor' => '1']);
        $this->oldCoefficient = AkCoefficientRule::create(['pak_rule_set_id' => $this->rule->id, 'functional_level_id' => $this->level->id, 'annual_coefficient' => '25']);
        $this->newLevel = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'CORRECTED', 'name' => 'Synthetic corrected level']);
        FunctionalPositionLevel::create(['functional_position_id' => $position->id, 'functional_level_id' => $this->newLevel->id]);
        AkCoefficientRule::create(['pak_rule_set_id' => $this->rule->id, 'functional_level_id' => $this->newLevel->id, 'annual_coefficient' => '37.5']);
        // Isolated fixture stands in for accepted case first-use lifecycle, never production preview behavior.
        DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['first_used_at' => '2026-01-01 00:00:00']);
        $this->case = PakCase::create(['person_id' => $person->id, 'pak_rule_set_id' => $this->rule->id, 'purpose_type' => 'KP', 'period_start' => '2023-01-01', 'period_end' => '2026-12-31', 'employee_data_as_of' => '2026-01-01', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $this->history = EmployeePositionHistory::create(['person_id' => $person->id, 'position_name' => 'Synthetic position', 'position_type' => 'JF', 'functional_position_id' => $position->id, 'functional_level_id' => $this->level->id,
            'tmt_start' => '2023-01-01', 'sk_number' => 'SYNTHETIC-SK-001', 'sk_date' => '2022-12-15', 'source' => 'FIXTURE', 'source_ref' => 'Synthetic documentary reference', 'verified_by' => $this->actor->id, 'verified_at' => now(), 'created_by' => $this->actor->id]);
        // A pre-range history makes the alternate pair part of scalar discovery already.
        // This isolates fingerprint reread from B.3.3.E's unplanned-mapping rejection.
        app(EmployeeHistoryService::class)->record($this->actor, $person,
            array_replace($this->correctionInput(), ['tmt_start' => '2021-01-01', 'tmt_end' => '2022-12-31']), true);
        $this->case->refresh();
        foreach ([2023, 2024, 2025, 2026] as $year) {
            app(PerformanceEvaluationService::class)->createInitial($this->actor, $this->record($this->source($year)));
        }
    }

    private function correctionInput(): array
    {
        return ['position_name' => 'Synthetic corrected position', 'position_type' => 'JF',
            'functional_position_id' => $this->history->functional_position_id,
            'functional_level_id' => $this->newLevel->id, 'tmt_start' => '2023-01-01',
            'sk_number' => 'SYNTHETIC-CORRECTED-SK', 'sk_date' => '2022-12-20',
            'source_ref' => 'Synthetic corrected documentary reference'];
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
        $this->assertSame('75', (string) $preview->exact_total->getNumerator());
        $this->assertSame('1', (string) $preview->exact_total->getDenominator());
        $request = ConversionSaveRequest::from(['pak_case_id' => $this->case->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint]);

        return [$preview, $request, ['operation' => 'history', 'person' => $this->case->person_id,
            'history' => $this->correctionInput(), 'supersedes_id' => $this->history->id,
            'reason' => 'Synthetic position documentary correction']];
    }

    public static function orders(): array
    {
        return ['history_first' => [true], 'conversion_first' => [false]];
    }

    /** SQLite validates canonical fixture/provenance and final assertions, not concurrency. */
    #[DataProvider('orders')]
    public function test_nonlive_fixture_and_serial_outcomes(bool $historyFirst): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Non-live fixture validation uses SQLite only.');
        }
        $this->fixture(false);
        [$preview, $request, $correction] = $this->intent();
        $correct = fn () => app(EmployeeHistoryService::class)->record($this->actor,
            Person::findOrFail($correction['person']), $correction['history'], true,
            $correction['supersedes_id'], $correction['reason']);
        if ($historyFirst) {
            $correct();
            try {
                app(ConversionService::class)->saveInitial($this->actor, $request);
                $this->fail('Stale preview accepted');
            } catch (ValidationException $error) {
                $this->assertSame(['snapshot'], array_keys($error->errors()));
            }
        } else {
            app(ConversionService::class)->saveInitial($this->actor, $request);
            $correct();
        }
        $this->assertFinalState($historyFirst, $preview, $request);
    }

    #[DataProvider('orders')]
    public function test_position_correction_and_conversion_serialize_on_person(bool $historyFirst): void
    {
        $this->fixture(true);
        [$preview, $request, $correction] = $this->intent();
        $conversion = ['operation' => 'conversion', 'request' => get_object_vars($request)];
        $operations = $historyFirst ? [$correction, $conversion] : [$conversion, $correction];
        $workers = [];
        foreach ($operations as $index => $operation) {
            $input = $operation + ['db' => DB::connection()->getConfig(), 'root' => $this->root,
                'actor' => $this->actor->id, 'worker' => (string) $index,
                'barrier' => $index === 0 ? 'hold' : 'none', 'lock_table' => 'people'];
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
            $this->assertSame('`pak_02a1_test`.`people`', $wait->locked_table);
            $this->assertSame('PRIMARY', $wait->locked_index);
            touch($this->root.'/release');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), 'Worker failed; private stderr omitted.');
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
            $history = $results[$historyFirst ? 0 : 1];
            $conversion = $results[$historyFirst ? 1 : 0];
            $this->assertTrue($history['success']);
            $this->assertSame(! $historyFirst, $conversion['success']);
            if ($historyFirst) {
                $this->assertSame('VALIDATION_FAILED', $conversion['issue_code']);
                $this->assertSame(['snapshot'], $conversion['validation_fields']);
            }
            DB::purge(); // Independent committed-state reread after both processes have exited.
            $this->assertFinalState($historyFirst, $preview, $request);
            $this->assertSame($history['history_id'], EmployeePositionHistory::where('supersedes_id', $this->history->id)->sole()->id);
            fwrite(STDOUT, "\nPOSITION_CONVERSION_SERIALIZATION ".json_encode(['first' => $historyFirst ? 'history' : 'conversion',
                'observer' => $observer, 'wait' => $wait, 'workers' => $results])."\n");
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

    private function assertFinalState(bool $historyFirst, ConversionPreview $preview, ConversionSaveRequest $request): void
    {
        $old = $this->history->fresh();
        $new = EmployeePositionHistory::where('supersedes_id', $old->id)->sole();
        $this->assertNotNull($old->superseded_at);
        $this->assertNull($new->superseded_at);
        $this->assertSame($old->id, $new->supersedes_id);
        $this->assertSame($old->person_id, $new->person_id);
        $this->assertSame($this->level->id, $old->functional_level_id);
        $this->assertSame($this->newLevel->id, $new->functional_level_id);
        $this->assertSame($old->functional_position_id, $new->functional_position_id);
        $this->assertSame('2023-01-01', $new->tmt_start->format('Y-m-d'));
        $this->assertNotNull($new->verified_by);
        $this->assertNotNull($new->verified_at);
        $this->assertSame('SYNTHETIC-CORRECTED-SK', $new->sk_number);
        $this->assertSame('Synthetic corrected documentary reference', $new->source_ref);
        $this->assertSame(3, EmployeePositionHistory::where('person_id', $old->person_id)->count());
        $this->assertSame(1, EmployeePositionHistory::where('person_id', $old->person_id)->whereNull('superseded_at')->whereDate('tmt_start', '2023-01-01')->count());
        $this->assertSame(1, PakEvent::where('event_code', 'HISTORY_CORRECTED')->count());
        $this->assertSame($new->id, PakEvent::where('event_code', 'HISTORY_CORRECTED')->sole()->subject_id);
        $fresh = app(ConversionService::class)->preview($this->actor,
            new ConversionPreviewRequest($this->case->id, $request->requested_start, $request->requested_end));
        $this->assertSame([], $fresh->issues);
        $this->assertSame('225/2', $fresh->exact_total->getNumerator().'/'.$fresh->exact_total->getDenominator());
        $this->assertNotSame($preview->dependency_fingerprint, $fresh->dependency_fingerprint);
        $this->assertNotSame($preview->snapshot_fingerprint, $fresh->snapshot_fingerprint);
        $this->assertSame('2026-01-01 00:00:00', $this->rule->fresh()->getRawOriginal('first_used_at'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame($request->expected_case_lock_version + ($historyFirst ? 0 : 1), $this->case->fresh()->lock_version);
        $this->assertSame($historyFirst ? 0 : 1, PakConversionRun::count());
        $this->assertSame($historyFirst ? 0 : 4, PakConversionPeriod::count());
        $this->assertSame($historyFirst ? 0 : 1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        if ($historyFirst) {
            return;
        }
        $run = PakConversionRun::sole();
        $this->assertSame(1, $run->revision_number);
        $this->assertNull($run->predecessor_run_id);
        $this->assertNull($run->revision_reason);
        $this->assertSame($this->case->id, $run->active_case_id);
        $this->assertSame($this->case->person_id, $run->person_id);
        $this->assertSame($this->rule->id, $run->pak_rule_set_id);
        $this->assertSame($request->expected_case_lock_version + 1, $run->case_lock_version);
        $this->assertSame($run->id, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->sole()->subject_id);
        $periods = $run->periods()->orderBy('line_number')->get();
        $this->assertSame(['25/6', '25/1', '25/1', '125/6'], $periods->map(fn ($p) => $p->result_numerator.'/'.$p->result_denominator)->all());
        $this->assertSame('75/1', $run->total_numerator.'/'.$run->total_denominator);
        foreach ($periods as $period) {
            $this->assertSame($old->id, $period->employee_position_history_id);
            $this->assertNotSame($new->id, $period->employee_position_history_id);
            $this->assertSame($old->id, $period->position_snapshot['history_id']);
            // Single annual sources need no source-history proof; OD-04 assignment is separate.
            $this->assertNull($period->source_position_history_id);
            $this->assertNull($period->source_snapshot['history_id']);
            $this->assertSame($this->level->id, $period->functional_level_id);
            $this->assertSame($this->level->id, $period->position_snapshot['functional_level_id']);
            $this->assertSame($this->level->code, $period->position_snapshot['functional_level_code']);
            $this->assertSame($old->sk_number, $period->position_snapshot['sk_number']);
            $this->assertSame($this->oldCoefficient->id, $period->ak_coefficient_rule_id);
            $this->assertSame('25', (string) BigRational::of($period->annual_coefficient)->simplified());
            $this->assertSame($this->oldCoefficient->id, $period->rule_snapshot['coefficient']['childRuleId']);
            $this->assertSame('25', (string) BigRational::of($period->rule_snapshot['coefficient']['annualCoefficient'])->simplified());
        }
        $this->assertSame(ConversionManifestBuilder::canonical($preview->dependencies->groups), ConversionManifestBuilder::canonical($run->dependency_manifest));
        $this->assertSame($request->expected_dependency_fingerprint, $run->dependency_fingerprint);
        $this->assertSame($request->expected_snapshot_fingerprint, $run->snapshot_fingerprint);
        $this->assertSame(ConversionManifestBuilder::hash($run->dependency_manifest), $run->dependency_fingerprint);
        $this->assertSame(app(ConversionManifestBuilder::class)->snapshot($preview->dependencies, $preview->lines, $preview->exact_total, $preview->issues), $run->snapshot_fingerprint);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }
}
