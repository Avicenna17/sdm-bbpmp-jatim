<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionPreview;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionReplaceRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\DTOs\NormalizedSkpRecord;
use App\Domain\Pak\Services\ConversionManifestBuilder;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\FunctionalMasterService;
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

class ConversionReplacementMappingPlanConcurrencyTest extends TestCase
{
    private PakConversionRun $predecessor;

    private array $oldRun;

    private array $oldPeriods;

    private array $unchangedDependencies;

    private int $masterEventsBefore;

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
            fwrite(STDOUT, "\nREPLACEMENT_MAPPING_SERVER ".json_encode($identity)."\n");
        } else {
            $this->assertSame('sqlite', DB::getDriverName());
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->root = storage_path('framework/testing/conversion-concurrency-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0700, true);
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $this->root, 'visibility' => 'private', 'throw' => false]]);
        Storage::forgetDisk('local');
        $this->actor = User::factory()->create(['is_active' => true]);
        foreach (['pak.access', 'pak.skp.manage', 'pak.master.manage', ...ConversionService::PERMISSIONS] as $permission) {
            $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        // Entirely synthetic integration data. This is NOT Nurmaulida documentary evidence.
        $person = Person::factory()->create(['canonical_name' => 'Synthetic Master Conversion Subject', 'nip' => '001234567890123456']);
        $category = FunctionalCategory::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        $this->level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'PREVIEW', 'name' => 'Original', 'sort_order' => 0, 'is_active' => true]);
        $position = FunctionalPosition::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        // Deliberately absent: unchanged history pairs remain saveable without a mapping.
        $this->assertSame(0, FunctionalPositionLevel::count());
        $this->rule = PakRuleSet::create(['code' => 'PREVIEW', 'version' => '1', 'name' => 'Synthetic', 'valid_from' => '2023-01-01', 'valid_until' => '2026-12-31', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        PerformancePredicateRule::create(['pak_rule_set_id' => $this->rule->id, 'predicate_code' => 'BAIK', 'predicate_name' => 'Synthetic', 'predicate_factor' => '1']);
        $this->oldCoefficient = AkCoefficientRule::create(['pak_rule_set_id' => $this->rule->id, 'functional_level_id' => $this->level->id, 'annual_coefficient' => '25']);
        // Isolated fixture stands in for accepted case first-use lifecycle, never production preview behavior.
        DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['first_used_at' => '2026-01-01 00:00:00']);
        $this->case = PakCase::create(['person_id' => $person->id, 'pak_rule_set_id' => $this->rule->id, 'purpose_type' => 'KP', 'period_start' => '2023-01-01', 'period_end' => '2026-12-31', 'employee_data_as_of' => '2026-01-01', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $this->history = EmployeePositionHistory::create(['person_id' => $person->id, 'position_name' => 'Synthetic position', 'position_type' => 'JF', 'functional_position_id' => $position->id, 'functional_level_id' => $this->level->id,
            'tmt_start' => '2023-01-01', 'sk_number' => 'SYNTHETIC-SK-001', 'sk_date' => '2022-12-15', 'source' => 'FIXTURE', 'source_ref' => 'Synthetic documentary reference', 'verified_by' => $this->actor->id, 'verified_at' => now(), 'created_by' => $this->actor->id]);
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
        $this->assertSame('Original', $this->predecessor->dependency_manifest['history_dependencies']['levels'][0]['name']);
        $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $this->masterEventsBefore = PakEvent::where('event_code', 'MASTER_SAVED')->count();
        $this->assertSame(1, $this->masterEventsBefore);
        $this->assertSame(0, FunctionalPositionLevel::count());
        foreach (['performance_evaluations', 'employee_position_histories', 'functional_categories',
            'functional_positions', 'functional_levels', 'pak_rule_sets', 'performance_predicate_rules', 'ak_coefficient_rules'] as $table) {
            $this->unchangedDependencies[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
    }

    private function masterInput(): array
    {
        return ['functional_position_id' => $this->history->functional_position_id,
            'functional_level_id' => $this->level->id, 'is_active' => true];
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
            'revision_reason' => 'Synthetic explicit replacement before mapping creation', 'pak_case_id' => $this->case->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint]);

        return [$preview, $request, ['operation' => 'master', 'type' => 'mapping',
            'master_id' => null, 'master' => $this->masterInput()]];
    }

    public static function orders(): array
    {
        return ['mapping_first' => [true], 'replacement_first' => [false]];
    }

    /** SQLite validates canonical fixture/provenance and final assertions, not concurrency. */
    #[DataProvider('orders')]
    public function test_nonlive_absent_mapping_and_post_discovery_creation(bool $mappingFirst): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Non-live fixture validation uses SQLite only.');
        }
        $this->fixture(false);
        [$preview, $request, $mutation] = $this->intent();
        $update = fn () => app(FunctionalMasterService::class)->save($this->actor,
            $mutation['type'], $mutation['master'], $mutation['master_id']);
        if ($mappingFirst) {
            // Discovery precedes this hook. The public writer commits before replacement begins.
            // Guard recursion because the mapping service opens its own transaction.
            $injected = false;
            DB::connection()->beforeStartingTransaction(function () use (&$injected, $update) {
                if (! $injected) {
                    $injected = true;
                    $this->assertSame(0, DB::transactionLevel());
                    $this->assertFalse(DB::connection()->getPdo()->inTransaction());
                    $update();
                    $this->assertSame(0, DB::transactionLevel());
                }
            });
            try {
                app(ConversionService::class)->replace($this->actor, $request);
                $this->fail('Obsolete mapping plan accepted');
            } catch (ValidationException $error) {
                $this->assertSame(['snapshot' => ['Dependency lock plan changed. Obtain a new preview.']], $error->errors());
                $this->assertTrue($injected);
            }
        } else {
            app(ConversionService::class)->replace($this->actor, $request);
            $this->assertSame(0, FunctionalPositionLevel::count());
            $update();
        }
        $this->assertFinalState($mappingFirst, $preview, $request);
    }

    #[DataProvider('orders')]
    public function test_mapping_creation_and_replacement_serialize_on_parent_position(bool $mappingFirst): void
    {
        $this->fixture(true);
        [$preview, $request, $mutation] = $this->intent();
        $conversion = ['operation' => 'conversion_replace', 'request' => get_object_vars($request)];
        $operations = $mappingFirst ? [$mutation, $conversion] : [$conversion, $mutation];
        $workers = [];
        foreach ($operations as $index => $operation) {
            $input = $operation + ['db' => DB::connection()->getConfig(), 'root' => $this->root,
                'actor' => $this->actor->id, 'worker' => (string) $index,
                'barrier' => $index === 0 ? 'hold' : 'none', 'lock_table' => 'functional_positions'];
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
            $this->assertSame('`pak_02a1_test`.`functional_positions`', $wait->locked_table);
            $this->assertSame('PRIMARY', $wait->locked_index);
            $this->assertSame(0, FunctionalPositionLevel::count(), 'Mapping must be absent before release.');
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
            $master = $results[$mappingFirst ? 0 : 1];
            $conversion = $results[$mappingFirst ? 1 : 0];
            $this->assertTrue($master['success']);
            $this->assertSame(! $mappingFirst, $conversion['success']);
            if ($mappingFirst) {
                $this->assertSame('VALIDATION_FAILED', $conversion['issue_code']);
                $this->assertSame(['snapshot'], $conversion['validation_fields']);
                $this->assertSame(ValidationException::class, $conversion['failure_class']);
                $this->assertSame(['snapshot' => ['Dependency lock plan changed. Obtain a new preview.']], $conversion['lock_plan_errors']);
            }
            $this->assertSame(0, $conversion['pending_callbacks']);
            $this->assertSame(0, $conversion['committed_callbacks']);
            DB::purge(); // Independent committed-state reread after both processes have exited.
            $this->assertFinalState($mappingFirst, $preview, $request);
            if (! $mappingFirst) {
                $this->assertSame(PakConversionRun::where('revision_number', 2)->sole()->id, $conversion['run_id']);
            }
            fwrite(STDOUT, "\nREPLACEMENT_MAPPING_SERIALIZATION ".json_encode(['first' => $mappingFirst ? 'master' : 'replacement',
                'observer' => $observer, 'wait' => $wait, 'mapping_count_before_release' => 0, 'workers' => $results])."\n");
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
                $this->fail('FunctionalPosition barrier/lock-wait timeout.');
            }
            usleep(200000);
        }
    }

    private function assertFinalState(bool $mappingFirst, ConversionPreview $preview, ConversionReplaceRequest $request): void
    {
        $this->assertSame(1, FunctionalPositionLevel::count());
        $mapping = FunctionalPositionLevel::sole();
        $this->assertSame($this->history->functional_position_id, $mapping->functional_position_id);
        $this->assertSame($this->level->id, $mapping->functional_level_id);
        $this->assertTrue($mapping->is_active);
        $this->assertSame($this->masterEventsBefore + 1, PakEvent::where('event_code', 'MASTER_SAVED')->count());
        $mappingEvent = PakEvent::where('event_code', 'MASTER_SAVED')->orderByDesc('id')->firstOrFail();
        $this->assertSame($mapping->id, $mappingEvent->subject_id);
        $this->assertSame($mapping->functional_position_id, $mappingEvent->payload['after']['functional_position_id']);
        $this->assertSame($mapping->functional_level_id, $mappingEvent->payload['after']['functional_level_id']);
        foreach ($this->unchangedDependencies as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), $table);
        }
        $this->assertSame(1, EmployeePositionHistory::count());
        $this->assertNull($this->history->fresh()->superseded_at);
        $fresh = app(ConversionService::class)->preview($this->actor,
            new ConversionPreviewRequest($this->case->id, $request->requested_start, $request->requested_end));
        $this->assertSame([], $fresh->issues);
        $this->assertSame('75/1', $fresh->exact_total->getNumerator().'/'.$fresh->exact_total->getDenominator());
        // Mapping identity is coordination-only; neither semantic fingerprint changes.
        $this->assertSame($preview->dependency_fingerprint, $fresh->dependency_fingerprint);
        $this->assertSame($preview->snapshot_fingerprint, $fresh->snapshot_fingerprint);
        $this->assertSame(ConversionManifestBuilder::canonical($preview->dependencies->groups), ConversionManifestBuilder::canonical($fresh->dependencies->groups));
        $this->assertSame('2026-01-01 00:00:00', $this->rule->fresh()->getRawOriginal('first_used_at'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame($request->expected_case_lock_version + ($mappingFirst ? 0 : 1), $this->case->fresh()->lock_version);
        $this->assertSame($mappingFirst ? 1 : 2, PakConversionRun::count());
        $this->assertSame($mappingFirst ? 4 : 8, PakConversionPeriod::count());
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->assertSame($mappingFirst ? 0 : 1, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $prior = $this->predecessor->fresh();
        $this->assertSame('Original', $prior->dependency_manifest['history_dependencies']['levels'][0]['name']);
        $this->assertEquals($this->oldPeriods, DB::table('pak_conversion_periods')->where('pak_conversion_run_id', $prior->id)->orderBy('id')->get()->toArray());
        $this->assertSame(1, PakConversionRun::whereNotNull('active_case_id')->count());
        foreach ($this->oldRun as $field => $value) {
            if ($mappingFirst || ! in_array($field, ['status', 'active_case_id', 'superseded_by_user_id', 'superseded_at', 'updated_at'], true)) {
                $this->assertSame($value, $prior->getRawOriginal($field), $field);
            }
        }
        fwrite(STDOUT, "\nREPLACEMENT_MAPPING_FINAL ".json_encode([
            'order' => $mappingFirst ? 'mapping_first' : 'replacement_first',
            'run_count' => PakConversionRun::count(), 'revisions' => PakConversionRun::orderBy('revision_number')->pluck('revision_number')->all(),
            'active_runs' => 1, 'version_before' => $request->expected_case_lock_version,
            'version_after' => $this->case->fresh()->lock_version, 'periods' => PakConversionPeriod::count(),
            'saved_events' => 1, 'replaced_events' => $mappingFirst ? 0 : 1,
            'master_events_before' => $this->masterEventsBefore, 'master_events_after' => PakEvent::where('event_code', 'MASTER_SAVED')->count(),
            'mapping_count' => 1, 'mapping_id' => $mapping->id, 'fingerprints_equal' => true,
            'rejection' => $mappingFirst ? 'Dependency lock plan changed. Obtain a new preview.' : null,
            'revision_2_frozen_name' => $mappingFirst ? null : PakConversionRun::where('revision_number', 2)->sole()->dependency_manifest['history_dependencies']['levels'][0]['name'],
            'fresh_total' => '75/1',
        ])."\n");
        if ($mappingFirst) {
            $this->assertSame('PREVIEW', $prior->status->value);
            $this->assertSame($this->case->id, $prior->active_case_id);
            $this->assertNull($prior->superseded_at);
            $this->assertNull($prior->superseded_by_user_id);

            return;
        }
        $this->assertSame('SUPERSEDED', $prior->status->value);
        $this->assertNull($prior->active_case_id);
        $this->assertSame($this->actor->id, $prior->superseded_by_user_id);
        $this->assertNotNull($prior->superseded_at);
        $run = PakConversionRun::where('predecessor_run_id', $prior->id)->sole();
        $this->assertSame([1, 2], PakConversionRun::orderBy('revision_number')->pluck('revision_number')->all());
        $this->assertSame(2, $run->revision_number);
        $this->assertSame($prior->id, $run->predecessor_run_id);
        $this->assertSame($request->revision_reason, $run->revision_reason);
        $this->assertSame('PREVIEW', $run->status->value);
        $this->assertNull($run->superseded_at);
        $this->assertNull($run->superseded_by_user_id);
        $this->assertSame('Synthetic replacement intent label', $run->dependency_manifest['history_dependencies']['levels'][0]['name']);
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
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }
}
