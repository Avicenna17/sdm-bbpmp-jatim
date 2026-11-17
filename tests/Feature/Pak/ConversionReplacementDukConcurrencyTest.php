<?php

namespace Tests\Feature\Pak;

use App\Domain\Import\ImportService;
use App\Domain\Pak\DTOs\ConversionPreview;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionReplaceRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\DTOs\NormalizedSkpRecord;
use App\Domain\Pak\Services\ConversionManifestBuilder;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\FunctionalMasterService;
use App\Domain\Pak\Services\PakCaseService;
use App\Domain\Pak\Services\PerformanceEvaluationService;
use App\Domain\Pak\Services\SkpFingerprintSerializer;
use App\Models\AkCoefficientRule;
use App\Models\EmployeePositionHistory;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use App\Models\ImportBatch;
use App\Models\PakCase;
use App\Models\PakConversionPeriod;
use App\Models\PakConversionRun;
use App\Models\PakEvent;
use App\Models\PakRuleSet;
use App\Models\PerformancePredicateRule;
use App\Models\Person;
use App\Models\PersonnelSnapshot;
use App\Models\ReportingPeriod;
use App\Models\User;
use Brick\Math\BigRational;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\Support\Pak\DukCoordinationFixtures;
use Tests\Support\Pak\SyntheticSkpSourceAdapter;
use Tests\TestCase;

class ConversionReplacementDukConcurrencyTest extends TestCase
{
    use DukCoordinationFixtures;

    private PakConversionRun $predecessor;

    private array $oldRun;

    private array $oldPeriods;

    private Person $person;

    private ImportBatch $batch;

    private array $caseSnapshot;

    private array $unchangedDependencies;

    private string $firstUsedAt;

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
            fwrite(STDOUT, "\nREPLACEMENT_DUK_SERVER ".json_encode($identity)."\n");
        } else {
            $this->assertSame('sqlite', DB::getDriverName());
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->root = storage_path('framework/testing/conversion-concurrency-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0700, true);
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $this->root, 'visibility' => 'private', 'throw' => false]]);
        Storage::forgetDisk('local');
        $this->actor = User::factory()->create(['is_active' => true]);
        foreach (['pak.access', 'pak.skp.manage', 'pak.case.create', 'import.commit', 'pak.master.manage', ...ConversionService::PERMISSIONS] as $permission) {
            $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        // Entirely synthetic integration data. This is NOT Nurmaulida documentary evidence.
        $this->person = $person = Person::factory()->create(['canonical_name' => 'Original Person', 'normalized_name' => 'ORIGINAL PERSON', 'nip' => '001234567890123456', 'person_key' => hash('sha256', 'nip:001234567890123456')]);
        $category = FunctionalCategory::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        $this->level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'PREVIEW', 'name' => 'Original', 'sort_order' => 0, 'is_active' => true]);
        $position = FunctionalPosition::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        FunctionalPositionLevel::create(['functional_position_id' => $position->id, 'functional_level_id' => $this->level->id]);
        $this->rule = PakRuleSet::create(['code' => 'PREVIEW', 'version' => '1', 'name' => 'Synthetic', 'valid_from' => '2023-01-01', 'valid_until' => '2026-12-31', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        PerformancePredicateRule::create(['pak_rule_set_id' => $this->rule->id, 'predicate_code' => 'BAIK', 'predicate_name' => 'Synthetic', 'predicate_factor' => '1']);
        $this->oldCoefficient = AkCoefficientRule::create(['pak_rule_set_id' => $this->rule->id, 'functional_level_id' => $this->level->id, 'annual_coefficient' => '25']);
        $this->history = EmployeePositionHistory::create(['person_id' => $person->id, 'position_name' => 'Synthetic position', 'position_type' => 'JF', 'functional_position_id' => $position->id, 'functional_level_id' => $this->level->id,
            'tmt_start' => '2023-01-01', 'sk_number' => 'SYNTHETIC-SK-001', 'sk_date' => '2022-12-15', 'source' => 'FIXTURE', 'source_ref' => 'Synthetic documentary reference', 'verified_by' => $this->actor->id, 'verified_at' => now(), 'created_by' => $this->actor->id]);
        $this->case = app(PakCaseService::class)->create($this->actor, [
            'person_id' => $person->id, 'pak_rule_set_id' => $this->rule->id, 'purpose_type' => 'KP',
            'period_start' => '2023-01-01', 'period_end' => '2026-12-31', 'employee_data_as_of' => '2026-01-01']);
        $this->caseSnapshot = $this->case->employeeSnapshot->getAttributes();
        $this->firstUsedAt = $this->rule->fresh()->getRawOriginal('first_used_at');
        $this->assertSame('Original Person', $this->person->fresh()->canonical_name);
        $this->assertSame('Original Person', $this->case->employeeSnapshot->name);
        $this->case->refresh();
        foreach ([2023, 2024, 2025, 2026] as $year) {
            app(PerformanceEvaluationService::class)->createInitial($this->actor, $this->record($this->source($year)));
        }
        $initial = app(ConversionService::class)->preview($this->actor,
            new ConversionPreviewRequest($this->case->id, '2023-11-01', '2026-10-31'));
        $this->predecessor = app(ConversionService::class)->saveInitial($this->actor, ConversionSaveRequest::from([
            'pak_case_id' => $this->case->id, 'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $initial->expected_case_version,
            'expected_dependency_fingerprint' => $initial->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $initial->snapshot_fingerprint,
        ]));
        $this->assertSame('75/1', $this->predecessor->total_numerator.'/'.$this->predecessor->total_denominator);
        $this->assertSame(1, $this->predecessor->revision_number);
        $this->assertSame('PREVIEW', $this->predecessor->status->value);
        $this->assertSame($this->case->id, $this->predecessor->active_case_id);
        $this->assertNull($this->predecessor->superseded_at);
        $this->assertNull($this->predecessor->superseded_by_user_id);
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->oldRun = $this->predecessor->fresh()->getRawOriginal();
        $this->oldPeriods = DB::table('pak_conversion_periods')->where('pak_conversion_run_id', $this->predecessor->id)->orderBy('id')->get()->toArray();
        $this->assertCount(4, $this->oldPeriods);
        $this->assertSame(['25/6', '25/1', '25/1', '125/6'], array_map(fn ($p) => $p->result_numerator.'/'.$p->result_denominator, $this->oldPeriods));
        app(FunctionalMasterService::class)->save($this->actor, 'level', [
            'code' => $this->level->code, 'name' => 'Synthetic replacement intent label',
            'functional_category_id' => $this->level->functional_category_id,
            'sort_order' => $this->level->fresh()->sort_order, 'is_active' => true,
        ], $this->level->id);
        $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $this->assertSame(1, PakEvent::where('event_code', 'MASTER_SAVED')->count());
        // Capture after the deliberate rename; DUK must not change any of these tables.
        foreach (['performance_evaluations', 'employee_position_histories', 'functional_categories',
            'functional_positions', 'functional_levels', 'functional_position_levels', 'pak_rule_sets',
            'performance_predicate_rules', 'ak_coefficient_rules'] as $table) {
            $this->unchangedDependencies[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
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
        $this->assertSame('75', (string) $preview->exact_total->getNumerator());
        $this->assertSame('1', (string) $preview->exact_total->getDenominator());
        $this->assertNotSame($this->predecessor->dependency_fingerprint, $preview->dependency_fingerprint);
        $this->assertNotSame($this->predecessor->snapshot_fingerprint, $preview->snapshot_fingerprint);
        $this->assertSame('Synthetic replacement intent label', $preview->dependencies->groups['history_dependencies']['levels'][0]['name']);
        $this->assertSame($this->predecessor->case_lock_version, $preview->expected_case_version);
        $request = ConversionReplaceRequest::from(['expected_predecessor_run_id' => $this->predecessor->id,
            'revision_reason' => 'Synthetic explicit replacement before DUK', 'pak_case_id' => $this->case->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint]);

        // Reuse the accepted real XLSX/parser/checksum/staging fixture; commit reparses its bytes.
        // The Person already has the canonical parser key, so no identity migration is needed.
        $this->batch = $this->preparedDuk($this->actor, [[
            'person_key' => $this->person->person_key, 'nip_at_period' => $this->person->nip,
            'name_at_period' => 'Updated Person']]);
        $this->assertSame('validated', $this->batch->status);
        $row = app(ImportService::class)->rows($this->batch)[0];
        $this->assertSame($this->person->person_key, $row['person_key']);
        $this->assertSame($this->person->nip, $row['nip_at_period']);
        $this->assertSame('Original Person', $this->person->fresh()->canonical_name);

        $this->assertSame('ORIGINAL PERSON', $this->person->fresh()->normalized_name);
        $this->assertSame('Original Person', $this->case->employeeSnapshot()->sole()->name);

        return [$preview, $request, ['operation' => 'duk', 'batch_id' => $this->batch->id]];
    }

    public static function orders(): array
    {
        return ['duk_first' => [true], 'replacement_first' => [false]];
    }

    /** SQLite validates canonical fixture/provenance and final assertions, not concurrency. */
    #[DataProvider('orders')]
    public function test_nonlive_fixture_and_serial_outcomes(bool $dukFirst): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Non-live fixture validation uses SQLite only.');
        }
        $this->fixture(false);
        [$preview, $request] = $this->intent();
        $commitDuk = fn () => app(ImportService::class)->commit($this->actor, $this->batch);
        if ($dukFirst) {
            $commitDuk();
            $this->assertSame('Updated Person', $this->person->fresh()->canonical_name);
            app(ConversionService::class)->replace($this->actor, $request);
        } else {
            app(ConversionService::class)->replace($this->actor, $request);
            $commitDuk();
        }
        $this->assertFinalState($dukFirst, $preview, $request);
    }

    #[DataProvider('orders')]
    public function test_duk_and_conversion_serialize_on_person(bool $dukFirst): void
    {
        $this->fixture(true);
        [$preview, $request, $mutation] = $this->intent();
        $conversion = ['operation' => 'conversion_replace', 'request' => get_object_vars($request)];
        $operations = $dukFirst ? [$mutation, $conversion] : [$conversion, $mutation];
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
            $duk = $results[$dukFirst ? 0 : 1];
            $conversion = $results[$dukFirst ? 1 : 0];
            $this->assertTrue($duk['success']);
            $this->assertTrue($conversion['success']);
            $this->assertNotEmpty($conversion['run_id']);
            $this->assertSame('committed', $duk['status']);
            $this->assertSame(0, $conversion['pending_callbacks']);
            $this->assertSame(0, $conversion['committed_callbacks']);
            DB::purge(); // Independent committed-state reread after both processes have exited.
            $this->assertFinalState($dukFirst, $preview, $request);
            fwrite(STDOUT, "\nREPLACEMENT_DUK_SERIALIZATION ".json_encode(['first' => $dukFirst ? 'duk' : 'replacement',
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

    private function assertFinalState(bool $dukFirst, ConversionPreview $preview, ConversionReplaceRequest $request): void
    {
        $person = $this->person->fresh();
        $this->assertSame(1, Person::count());
        foreach (['id', 'person_key', 'nip'] as $field) {
            $this->assertSame($this->person->$field, $person->$field, $field);
        }
        $this->assertSame('Updated Person', $person->canonical_name);
        $this->assertSame('UPDATED PERSON', $person->normalized_name);
        $this->assertSame($person->id, $this->case->fresh()->person_id);
        $this->assertSame('Original Person', $this->case->employeeSnapshot()->sole()->name);
        $this->assertSame($this->caseSnapshot, $this->case->employeeSnapshot()->sole()->getAttributes());
        $this->assertSame(0, PakEvent::where('event_code', 'PAK_SNAPSHOT_REFRESHED')->count());
        $batch = $this->batch->fresh();
        $this->assertSame('committed', $batch->status);
        $this->assertNotNull($batch->committed_at);
        $period = ReportingPeriod::findOrFail($batch->reporting_period_id);
        $this->assertSame($batch->base_revision + 1, $period->revision);
        $this->assertSame('ready', $period->status);
        $this->assertSame(1, PersonnelSnapshot::count());
        $personnel = PersonnelSnapshot::sole();
        $this->assertSame($person->id, $personnel->person_id);
        $this->assertSame($batch->id, $personnel->import_batch_id);
        $this->assertSame($period->id, $personnel->reporting_period_id);
        $this->assertSame('Updated Person', $personnel->name_at_period);
        $this->assertSame($person->nip, $personnel->nip_at_period);
        foreach ($this->unchangedDependencies as $table => $before) {
            $this->assertSame($before, DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), $table);
        }
        $fresh = app(ConversionService::class)->preview($this->actor,
            new ConversionPreviewRequest($this->case->id, $request->requested_start, $request->requested_end));
        $this->assertSame([], $fresh->issues);
        $this->assertSame('75/1', $fresh->exact_total->getNumerator().'/'.$fresh->exact_total->getDenominator());
        // Person profile changes are deliberately not semantic conversion dependencies.
        $this->assertSame($preview->dependency_fingerprint, $fresh->dependency_fingerprint);
        $this->assertSame($preview->snapshot_fingerprint, $fresh->snapshot_fingerprint);
        $this->assertSame(ConversionManifestBuilder::canonical($preview->dependencies->groups), ConversionManifestBuilder::canonical($fresh->dependencies->groups));
        $this->assertSame($this->firstUsedAt, $this->rule->fresh()->getRawOriginal('first_used_at'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame($request->expected_case_lock_version + 1, $this->case->fresh()->lock_version);
        $this->assertSame(2, PakConversionRun::count());
        $this->assertSame([1, 2], PakConversionRun::orderBy('revision_number')->pluck('revision_number')->all());
        $this->assertSame(1, PakConversionRun::whereNotNull('active_case_id')->count());
        $this->assertSame(8, PakConversionPeriod::count());
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $this->assertSame(1, PakEvent::where('event_code', 'MASTER_SAVED')->count());
        $prior = $this->predecessor->fresh();
        $this->assertSame('SUPERSEDED', $prior->status->value);
        $this->assertNull($prior->active_case_id);
        $this->assertSame($this->actor->id, $prior->superseded_by_user_id);
        $this->assertNotNull($prior->superseded_at);
        foreach ($this->oldRun as $field => $value) {
            if (! in_array($field, ['status', 'active_case_id', 'superseded_by_user_id', 'superseded_at', 'updated_at'], true)) {
                $this->assertSame($value, $prior->getRawOriginal($field), $field);
            }
        }
        $this->assertEquals($this->oldPeriods, DB::table('pak_conversion_periods')->where('pak_conversion_run_id', $prior->id)->orderBy('id')->get()->toArray());
        $run = PakConversionRun::where('predecessor_run_id', $prior->id)->sole();
        $this->assertSame(2, $run->revision_number);
        $this->assertSame($prior->id, $run->predecessor_run_id);
        $this->assertSame($request->revision_reason, $run->revision_reason);
        $this->assertSame('PREVIEW', $run->status->value);
        $this->assertNull($run->superseded_at);
        $this->assertNull($run->superseded_by_user_id);
        $this->assertSame($this->case->id, $run->active_case_id);
        $this->assertSame($this->case->person_id, $run->person_id);
        $this->assertSame($this->rule->id, $run->pak_rule_set_id);
        $this->assertSame($request->expected_case_lock_version + 1, $run->case_lock_version);
        $event = PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->sole();
        $this->assertSame($run->id, $event->subject_id);
        $this->assertSame($prior->id, $event->payload['supersedes_id']);
        $periods = $run->periods()->orderBy('line_number')->get();
        $this->assertSame(['25/6', '25/1', '25/1', '125/6'], $periods->map(fn ($p) => $p->result_numerator.'/'.$p->result_denominator)->all());
        $this->assertSame('75/1', $run->total_numerator.'/'.$run->total_denominator);
        foreach ($periods as $period) {
            $this->assertSame($this->history->id, $period->employee_position_history_id);
            $this->assertSame($this->history->id, $period->position_snapshot['history_id']);
            // Single annual sources need no source-history proof; OD-04 assignment is separate.
            $this->assertNull($period->source_position_history_id);
            $this->assertNull($period->source_snapshot['history_id']);
            $this->assertSame($this->level->id, $period->functional_level_id);
            $this->assertSame($this->level->id, $period->position_snapshot['functional_level_id']);
            $this->assertSame($this->level->code, $period->position_snapshot['functional_level_code']);
            $this->assertSame('Synthetic replacement intent label', $period->position_snapshot['functional_level_name']);
            $this->assertSame($this->history->sk_number, $period->position_snapshot['sk_number']);
            $this->assertSame($this->oldCoefficient->id, $period->ak_coefficient_rule_id);
            $this->assertSame('25', (string) BigRational::of($period->annual_coefficient)->simplified());
            $this->assertSame($this->oldCoefficient->id, $period->rule_snapshot['coefficient']['childRuleId']);
            $this->assertSame('25', (string) BigRational::of($period->rule_snapshot['coefficient']['annualCoefficient'])->simplified());
        }
        $levels = $run->dependency_manifest['history_dependencies']['levels'];
        $this->assertCount(1, $levels);
        $this->assertSame($this->level->id, $levels[0]['id']);
        $this->assertSame($this->level->code, $levels[0]['code']);
        $this->assertSame($this->level->functional_category_id, $levels[0]['functional_category_id']);
        $this->assertSame('Synthetic replacement intent label', $levels[0]['name']);
        // Evidence uses the canonical raw database scalar, not the model's boolean cast.
        $this->assertSame(1, $levels[0]['is_active']);
        $this->assertSame(ConversionManifestBuilder::canonical($preview->dependencies->groups), ConversionManifestBuilder::canonical($run->dependency_manifest));
        $this->assertSame($request->expected_dependency_fingerprint, $run->dependency_fingerprint);
        $this->assertSame($request->expected_snapshot_fingerprint, $run->snapshot_fingerprint);
        $this->assertSame(ConversionManifestBuilder::hash($run->dependency_manifest), $run->dependency_fingerprint);
        $this->assertSame(app(ConversionManifestBuilder::class)->snapshot($preview->dependencies, $preview->lines, $preview->exact_total, $preview->issues), $run->snapshot_fingerprint);
        fwrite(STDOUT, "\nREPLACEMENT_DUK_FINAL ".json_encode([
            'order' => $dukFirst ? 'duk_first' : 'replacement_first', 'revisions' => [1, 2],
            'version_before' => $request->expected_case_lock_version, 'version_after' => $this->case->fresh()->lock_version,
            'person_before' => 'Original Person', 'person_after' => $person->canonical_name,
            'case_snapshot_name' => $this->case->employeeSnapshot()->sole()->name,
            'batch_status' => $batch->status, 'personnel_snapshots' => PersonnelSnapshot::count(),
            'master_events' => 1, 'saved_events' => 1, 'replaced_events' => 1, 'periods' => 8,
            'frozen_total' => '75/1', 'fresh_total' => '75/1',
            'dependency_fingerprint' => $request->expected_dependency_fingerprint,
            'snapshot_fingerprint' => $request->expected_snapshot_fingerprint,
            'fresh_fingerprints_equal' => true,
        ])."\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }
}
