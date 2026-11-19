<?php

namespace Tests\Feature\Pak;

use App\Domain\Import\ImportService;
use App\Domain\Pak\DTOs\ConversionAssessment;
use App\Domain\Pak\DTOs\ConversionAssessmentRequest;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionReviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\DTOs\NormalizedSkpRecord;
use App\Domain\Pak\Services\ConversionAssessmentService;
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

class ConversionReviewDukConcurrencyTest extends TestCase
{
    use DukCoordinationFixtures;

    private array $intentGroups;

    private Person $person;

    private ImportBatch $batch;

    private array $caseSnapshot;

    private array $unchangedDependencies;

    private string $firstUsedAt;

    private AkCoefficientRule $oldCoefficient;

    private PakConversionRun $predecessor;

    private array $oldRun;

    private array $oldPeriods;

    private array $oldCase;

    private array $oldAudit;

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
        $identity = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS db, CONNECTION_ID() AS connection_id');
        $this->assertSame('pak_02a1_test', $identity->db);
        $this->assertStringStartsWith('10.5.11-MariaDB', $identity->version);
        fwrite(STDOUT, "\nREVIEW_DUK_SERVER ".json_encode($identity)."\n");
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->root = storage_path('framework/testing/review-duk-concurrency-'.bin2hex(random_bytes(8)));
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
            'code' => $this->level->code, 'name' => 'Synthetic review intent label',
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
        $assessment = app(ConversionAssessmentService::class)->assess($this->actor,
            ConversionAssessmentRequest::from(['pak_case_id' => $this->case->id, 'run_id' => $this->predecessor->id]));
        $this->assertSame('PREVIEW', $assessment->observed_run_status);
        $this->assertSame(0, $assessment->assessment_sequence);
        $this->assertSame('CHANGED', $assessment->comparison);
        $this->assertTrue($assessment->is_current_active);
        $this->assertSame($this->predecessor->case_lock_version, $assessment->observed_case_lock_version);
        $request = ConversionReviewRequest::from([
            'pak_case_id' => $this->case->id, 'run_id' => $this->predecessor->id,
            'expected_run_status' => 'PREVIEW', 'expected_case_lock_version' => $assessment->observed_case_lock_version,
            'expected_assessment_sequence' => 0, 'expected_assessment_fingerprint' => $assessment->assessment_fingerprint,
            'reason' => 'Synthetic review before DUK commit',
        ]);

        $preview = app(ConversionService::class)->preview($this->actor,
            new ConversionPreviewRequest($this->case->id, '2023-11-01', '2026-10-31'));
        $this->intentGroups = $preview->dependencies->groups;
        $this->assertSame('Synthetic review intent label', $this->intentGroups['history_dependencies']['levels'][0]['name']);
        $this->assertSame($assessment->current_dependency_fingerprint, $preview->dependency_fingerprint);
        $this->assertSame($assessment->current_snapshot_fingerprint, $preview->snapshot_fingerprint);
        $this->assertSame('75/1', $preview->exact_total->getNumerator().'/'.$preview->exact_total->getDenominator());
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

        return [$assessment, $request, ['operation' => 'duk', 'batch_id' => $this->batch->id]];
    }

    public static function orders(): array
    {
        return ['review_first' => [false], 'duk_first' => [true]];
    }

    #[DataProvider('orders')]
    public function test_duk_and_review_serialize_on_person(bool $dukFirst): void
    {
        $this->fixture();
        [$assessment, $request, $mutation] = $this->intent();
        $this->oldCase = $this->case->fresh()->getRawOriginal();
        $this->oldAudit = DB::table('pak_events')->orderBy('id')->get()->toArray();
        $this->assertNull($this->history->fresh()->superseded_at);
        $this->assertSame($this->level->id, $this->history->fresh()->functional_level_id);
        $conversion = ['operation' => 'conversion_review', 'request' => get_object_vars($request)];
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
            $this->assertSame($ids[1], (int) $wait->waiter);
            $this->assertSame($ids[0], (int) $wait->blocker);
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning());
            }
            touch($this->root.'/release');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), 'Worker failed; private stderr omitted.');
                $this->assertTrue($worker->getErrorOutput() === '', 'Unexpected private worker stderr omitted.');
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
            $dukResult = $results[$dukFirst ? 0 : 1];
            $conversion = $results[$dukFirst ? 1 : 0];
            $this->assertTrue($dukResult['success']);
            $this->assertTrue($conversion['success'], 'DUK profile changes must not stale the frozen review token.');
            $this->assertNull($conversion['issue_code']);
            $this->assertSame($request->run_id, $conversion['run_id']);
            $this->assertSame('REQUIRES_REVIEW', $conversion['status']);
            $this->assertSame(1, $conversion['assessment_sequence']);
            $this->assertSame('committed', $dukResult['status']);
            $this->assertSame(0, $conversion['pending_callbacks']);
            $this->assertSame(0, $conversion['committed_callbacks']);
            DB::purge(); // Independent committed-state reread after both processes have exited.
            $this->assertFinalState($dukFirst, $assessment, $request);
            fwrite(STDOUT, "\n".($dukFirst ? 'DUK_FIRST_REVIEW_SERIALIZATION ' : 'REVIEW_FIRST_DUK_SERIALIZATION ').json_encode(['first' => $dukFirst ? 'duk' : 'review',
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

    private function await(callable $condition, array $workers = []): void
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

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    private function assertFinalState(bool $dukFirst, ConversionAssessment $before, ConversionReviewRequest $request): void
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
        foreach ($this->unchangedDependencies as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), $table);
        }
        $this->assertSame($this->firstUsedAt, $this->rule->fresh()->getRawOriginal('first_used_at'));
        $this->assertSame($this->oldCase, $this->case->fresh()->getRawOriginal());
        $this->assertSame($request->expected_case_lock_version, $this->case->fresh()->lock_version);
        $this->assertSame(1, PakConversionRun::count());
        $run = $this->predecessor->fresh();
        $this->assertSame($request->run_id, $run->id);
        $this->assertSame(1, $run->revision_number);
        $this->assertSame($request->expected_case_lock_version, $run->case_lock_version);
        $this->assertSame($this->case->id, $run->active_case_id);
        $this->assertSame(4, PakConversionPeriod::count());
        $this->assertEquals($this->oldPeriods, DB::table('pak_conversion_periods')->orderBy('id')->get()->toArray());
        $this->assertSame('75/1', $run->total_numerator.'/'.$run->total_denominator);
        $this->assertSame('Original', $run->dependency_manifest['history_dependencies']['levels'][0]['name']);
        $allowed = ['status', 'review_assessment', 'review_assessed_by', 'review_assessed_at', 'updated_at'];
        $this->assertSame(array_diff_key($this->oldRun, array_flip($allowed)), array_diff_key($run->getRawOriginal(), array_flip($allowed)));
        $this->assertSame('REQUIRES_REVIEW', $run->status->value);
        // Both orders must persist exactly the pre-DUK assessment, including its token.
        $this->assertSame([
            'schema_version' => 1, 'assessment_sequence' => 1, 'comparison' => $before->comparison,
            'assessment_fingerprint' => $before->assessment_fingerprint,
            'current_dependency_fingerprint' => $before->current_dependency_fingerprint,
            'current_snapshot_fingerprint' => $before->current_snapshot_fingerprint,
            'changed_groups' => $before->changed_groups, 'current_issue_codes' => $before->current_issue_codes,
            'failure_code' => $before->failure_code, 'reason' => $request->reason,
        ], $run->review_assessment);
        $this->assertSame($this->actor->id, $run->review_assessed_by);
        $this->assertNotNull($run->review_assessed_at);
        $audit = DB::table('pak_events')->orderBy('id')->get()->toArray();
        $this->assertCount(count($this->oldAudit) + 1, $audit);
        $this->assertEquals($this->oldAudit, array_slice($audit, 0, count($this->oldAudit)));
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->count());
        $this->assertSame(1, PakEvent::where('event_code', 'MASTER_SAVED')->count());
        $review = PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->sole();
        $this->assertSame('PakConversionRun', $review->subject_type);
        $this->assertSame($run->id, $review->subject_id);
        $this->assertSame($this->case->id, $review->pak_case_id);
        $this->assertSame($this->actor->id, $review->actor_id);
        $this->assertSame(1, $review->payload['after']['assessment_sequence']);
        // Writer ordering is proven by the observed Person lock wait, not timestamp resolution.
        $rawRun = $run->getRawOriginal();
        $fresh = app(ConversionAssessmentService::class)->assess($this->actor,
            ConversionAssessmentRequest::from(['pak_case_id' => $this->case->id, 'run_id' => $run->id]));
        $this->assertSame('CHANGED', $fresh->comparison);
        $this->assertSame([], $fresh->current_issue_codes);
        $this->assertSame($request->expected_case_lock_version, $fresh->observed_case_lock_version);
        $this->assertSame(1, $fresh->assessment_sequence);
        $this->assertSame($run->status->value, $fresh->observed_run_status);
        // Only review status/sequence changes the token; DUK leaves semantic hashes unchanged.
        $this->assertNotSame($before->assessment_fingerprint, $fresh->assessment_fingerprint);
        $this->assertSame($before->current_dependency_fingerprint, $fresh->current_dependency_fingerprint);
        $this->assertSame($before->current_snapshot_fingerprint, $fresh->current_snapshot_fingerprint);
        $this->assertSame($before->changed_groups, $fresh->changed_groups);
        $this->assertSame($before->current_issue_codes, $fresh->current_issue_codes);
        $this->assertSame($before->failure_code, $fresh->failure_code);
        $preview = app(ConversionService::class)->preview($this->actor,
            new ConversionPreviewRequest($this->case->id, '2023-11-01', '2026-10-31'));
        $this->assertSame([], $preview->issues);
        $this->assertSame('75/1', $preview->exact_total->getNumerator().'/'.$preview->exact_total->getDenominator());
        $this->assertSame($fresh->current_dependency_fingerprint, $preview->dependency_fingerprint);
        $this->assertSame($fresh->current_snapshot_fingerprint, $preview->snapshot_fingerprint);
        $groups = $preview->dependencies->groups;
        $this->assertSame('Synthetic review intent label', $groups['history_dependencies']['levels'][0]['name']);
        $this->assertSame(ConversionManifestBuilder::canonical($this->intentGroups), ConversionManifestBuilder::canonical($groups));
        foreach ($preview->lines as $line) {
            $this->assertSame($this->history->id, $line->position->history_id);
            $this->assertSame($this->level->id, $line->position->functional_level_id);
            $this->assertSame('Synthetic review intent label', $line->position->functional_level_name);
            $this->assertSame($this->oldCoefficient->id, $line->coefficient->childRuleId);
            $this->assertTrue(BigRational::of($line->coefficient->annualCoefficient)->isEqualTo(25));
        }
        $this->assertSame($rawRun, $run->fresh()->getRawOriginal());
        $this->assertEquals($audit, DB::table('pak_events')->orderBy('id')->get()->toArray());
        $this->assertEquals($this->oldPeriods, DB::table('pak_conversion_periods')->orderBy('id')->get()->toArray());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertCount(0, app('db.transactions')->getPendingTransactions());
        $this->assertCount(0, app('db.transactions')->getCommittedTransactions());
        $this->await(fn () => (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX')->n === 0);
        fwrite(STDOUT, "\n".($dukFirst ? 'DUK_FIRST_REVIEW_FINAL ' : 'REVIEW_FIRST_DUK_FINAL ').json_encode([
            'run_id' => $run->id, 'run_count' => 1, 'run_status' => $run->status->value,
            'review_sequence' => $fresh->assessment_sequence, 'case_version_before' => $request->expected_case_lock_version,
            'case_version_after' => $this->case->fresh()->lock_version, 'saved_events' => 1,
            'review_events' => 1, 'replacement_events' => 0, 'master_events' => 1,
            'person_before' => 'Original Person', 'person_after' => $person->canonical_name,
            'case_snapshot_name' => $this->case->employeeSnapshot()->sole()->name,
            'batch_status' => $batch->status, 'reporting_status' => $period->status,
            'reporting_revision' => $period->revision, 'personnel_snapshots' => 1,
            'periods_unchanged' => true, 'saved_total' => '75/1', 'fresh_total' => '75/1',
            'fresh_dependency_equal' => true, 'fresh_snapshot_equal' => true,
            'fresh_assessment_token_changed_by_lifecycle' => true,
            'remaining_innodb_transactions' => 0,
        ])."\n");
    }
}
