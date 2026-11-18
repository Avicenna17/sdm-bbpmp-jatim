<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionAssessmentRequest;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
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
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConversionReviewConcurrencyTest extends TestCase
{
    private string $root;

    private PakConversionRun $predecessor;

    private array $oldRun;

    private array $oldPeriods;

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
        fwrite(STDOUT, "\nREVIEW_REVIEW_SERVER ".json_encode($identity)."\n");
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->root = storage_path('framework/testing/review-concurrency-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0700, true);
        $this->actor = User::factory()->create(['is_active' => true]);
        foreach (['pak.access', 'pak.master.manage', ...ConversionService::PERMISSIONS] as $permission) {
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
        $preview = $this->preview();
        $this->assertSame('75', (string) $preview->exact_total);
        $this->predecessor = app(ConversionService::class)->saveInitial($this->actor, ConversionSaveRequest::from([
            'pak_case_id' => $this->case->id, 'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint,
        ]));
        app(FunctionalMasterService::class)->save($this->actor, 'level', [
            'code' => $this->level->code, 'name' => 'Synthetic review dependency change',
            'functional_category_id' => $this->level->functional_category_id,
            'sort_order' => $this->level->fresh()->sort_order, 'is_active' => true,
        ], $this->level->id);
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

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    private function worker(string $name, ConversionReviewRequest $request, bool $hold): Process
    {
        $input = ['operation' => 'conversion_review', 'request' => get_object_vars($request),
            'actor' => $this->actor->id, 'db' => DB::connection()->getConfig(), 'root' => $this->root,
            'worker' => $name, 'barrier' => $hold ? 'hold' : 'none', 'lock_table' => 'pak_cases'];

        return new Process([PHP_BINARY, base_path('tests/Support/Pak/concurrency-worker.php')], base_path(),
            ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => 'bootstrap/cache/skp-test-unused-config.php'],
            json_encode($input, JSON_THROW_ON_ERROR), 25);
    }

    private function await(callable $condition, array $workers = []): void
    {
        $deadline = microtime(true) + 12;
        while (! $condition()) {
            foreach ($workers as $worker) {
                if ($worker->isStarted()) {
                    $worker->checkTimeout();
                    $this->assertTrue($worker->isRunning(), 'Worker exited before barrier/lock proof; private stderr omitted.');
                }
            }
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
        $this->assertSame('', $worker->getErrorOutput(), 'Unexpected worker stderr; private details omitted.');
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertFalse($result['pdo_active']);
        $this->assertSame(1, $result['attempts']);
        $this->assertSame([], $result['diagnostics']);
        $this->assertSame(0, $result['pending_callbacks']);
        $this->assertSame(0, $result['committed_callbacks']);

        return $result;
    }

    private function reviewRequest(): ConversionReviewRequest
    {
        $assessment = app(ConversionAssessmentService::class)->assess($this->actor,
            ConversionAssessmentRequest::from(['pak_case_id' => $this->case->id, 'run_id' => $this->predecessor->id]));
        $this->assertSame('CHANGED', $assessment->comparison);
        $this->assertTrue($assessment->is_current_active);

        return ConversionReviewRequest::from([
            'pak_case_id' => $assessment->pak_case_id, 'run_id' => $assessment->run_id,
            'expected_run_status' => $assessment->observed_run_status,
            'expected_case_lock_version' => $assessment->observed_case_lock_version,
            'expected_assessment_sequence' => $assessment->assessment_sequence,
            'expected_assessment_fingerprint' => $assessment->assessment_fingerprint,
            'reason' => 'Synthetic explicit concurrent review',
        ]);
    }

    public static function states(): array
    {
        return ['review_sequence' => ['REQUIRES_REVIEW', 1, 'expected_assessment_sequence'],
            'preview_status' => ['PREVIEW', 0, 'expected_run_status']];
    }

    #[DataProvider('states')]
    public function test_identical_reviews_serialize_without_lost_updates(string $status, int $sequence, string $loserField): void
    {
        if ($status === 'REQUIRES_REVIEW') {
            // Establish legitimate existing metadata through the public mutation, never direct SQL.
            app(ConversionReviewService::class)->assessReview($this->actor, $this->reviewRequest());
        }
        $request = $this->reviewRequest();
        $this->assertSame($status, $request->expected_run_status);
        $this->assertSame($sequence, $request->expected_assessment_sequence);
        $this->assertSame(1, PakConversionRun::count());
        $this->assertSame($sequence, PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->count());
        $this->oldRun = $this->predecessor->fresh()->getRawOriginal();
        $this->oldPeriods = DB::table('pak_conversion_periods')->orderBy('id')->get()->toArray();
        $oldCase = $this->case->fresh()->getRawOriginal();
        $oldAudit = DB::table('pak_events')->orderBy('id')->get()->toArray();
        $assessment = app(ConversionAssessmentService::class)->assess($this->actor,
            ConversionAssessmentRequest::from(['pak_case_id' => $this->case->id, 'run_id' => $this->predecessor->id]));
        $workers = [$this->worker('a', $request, true), $this->worker('b', $request, false)];
        try {
            $workers[0]->start();
            $this->await(fn () => is_file($this->root.'/a.held'), [$workers[0]]);
            $workers[1]->start();
            $this->await(fn () => is_file($this->root.'/b.connection'), $workers);
            $ids = [(int) file_get_contents($this->root.'/a.connection'), (int) file_get_contents($this->root.'/b.connection')];
            $this->assertNotSame($ids[0], $ids[1]);
            DB::purge();
            $observer = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertNotContains($observer, $ids);
            $wait = null;
            $this->await(function () use ($ids, &$wait) {
                $wait = DB::selectOne('SELECT r.trx_mysql_thread_id AS waiter, b.trx_mysql_thread_id AS blocker, l.lock_table AS locked_table, l.lock_index AS locked_index FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id WHERE r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=?', [$ids[1], $ids[0]]);

                return $wait !== null;
            }, $workers);
            // assessReview locks this exact Case PK first; both requests target the same case.
            $this->assertSame($ids[1], (int) $wait->waiter);
            $this->assertSame($ids[0], (int) $wait->blocker);
            $this->assertSame('`pak_02a1_test`.`pak_cases`', $wait->locked_table);
            $this->assertSame('PRIMARY', $wait->locked_index);
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning());
            }
            touch($this->root.'/release');
            $results = array_map(fn ($worker) => $this->workerResult($worker), $workers);
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            $this->assertNotContains(getmypid(), array_column($results, 'pid'));
            $this->assertSame($ids, array_column($results, 'connection_id'));
            $this->assertCount(1, array_filter($results, fn ($result) => $result['success']));
            $this->assertTrue($results[0]['success']);
            $this->assertSame($request->run_id, $results[0]['run_id']);
            $this->assertSame('REQUIRES_REVIEW', $results[0]['status']);
            $this->assertSame($sequence + 1, $results[0]['assessment_sequence']);
            $this->assertFalse($results[1]['success']);
            $this->assertSame('VALIDATION_FAILED', $results[1]['issue_code']);
            $this->assertSame(ValidationException::class, $results[1]['failure_class']);
            $this->assertSame([$loserField], $results[1]['validation_fields']);
            fwrite(STDOUT, "\nREVIEW_REVIEW_SERIALIZATION ".json_encode(['initial_status' => $status,
                'observer' => $observer, 'wait' => $wait, 'workers' => $results])."\n");

            DB::purge(); // Independent committed-state reread after both workers have exited.
            $this->assertSame(1, PakConversionRun::count());
            $run = PakConversionRun::findOrFail($request->run_id);
            $this->assertSame('REQUIRES_REVIEW', $run->status->value);
            $this->assertSame($oldCase, $this->case->fresh()->getRawOriginal());
            $allowed = array_flip(['status', 'review_assessment', 'review_assessed_by', 'review_assessed_at', 'updated_at']);
            $this->assertSame(array_diff_key($this->oldRun, $allowed), array_diff_key($run->getRawOriginal(), $allowed));
            $this->assertEquals($this->oldPeriods, DB::table('pak_conversion_periods')->orderBy('id')->get()->toArray());
            $this->assertSame(4, PakConversionPeriod::count());
            $this->assertSame('75/1', $run->total_numerator.'/'.$run->total_denominator);
            $this->assertSame($this->actor->id, $run->review_assessed_by);
            $this->assertNotNull($run->review_assessed_at);
            $this->assertSame([
                'schema_version' => 1, 'assessment_sequence' => $sequence + 1,
                'comparison' => $assessment->comparison, 'assessment_fingerprint' => $request->expected_assessment_fingerprint,
                'current_dependency_fingerprint' => $assessment->current_dependency_fingerprint,
                'current_snapshot_fingerprint' => $assessment->current_snapshot_fingerprint,
                'changed_groups' => $assessment->changed_groups, 'current_issue_codes' => $assessment->current_issue_codes,
                'failure_code' => $assessment->failure_code, 'reason' => $request->reason,
            ], $run->review_assessment);
            $audit = DB::table('pak_events')->orderBy('id')->get()->toArray();
            $this->assertCount(count($oldAudit) + 1, $audit);
            $this->assertEquals($oldAudit, array_slice($audit, 0, count($oldAudit)));
            $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
            $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
            $this->assertSame($sequence + 1, PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->count());
            $event = PakEvent::orderByDesc('id')->firstOrFail();
            $this->assertSame('CONVERSION_RUN_REVIEW_ASSESSED', $event->event_code);
            $this->assertSame('PakConversionRun', $event->subject_type);
            $this->assertSame($request->run_id, $event->subject_id);
            $this->assertSame($request->pak_case_id, $event->pak_case_id);
            $this->assertSame($this->actor->id, $event->actor_id);
            $this->assertSame($sequence, $event->payload['before']['assessment_sequence']);
            $this->assertSame($sequence + 1, $event->payload['after']['assessment_sequence']);
            $this->assertSame(['before', 'after', 'reason'], array_keys($event->payload));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->await(fn () => (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX')->n === 0);
            fwrite(STDOUT, "\nREVIEW_REVIEW_FINAL ".json_encode(['initial_status' => $status,
                'status' => $run->status->value, 'sequence_before' => $sequence, 'sequence_after' => $run->review_assessment['assessment_sequence'],
                'case_version_before' => $request->expected_case_lock_version, 'case_version_after' => $this->case->fresh()->lock_version,
                'run_count' => 1, 'period_count' => 4, 'new_review_audits' => 1, 'total_review_audits' => $sequence + 1,
                'saved_audits' => 1, 'replaced_audits' => 0, 'immutable_evidence_unchanged' => true,
                'remaining_innodb_transactions' => 0, 'remaining_workers' => 0])."\n");
        } finally {
            touch($this->root.'/release');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            foreach ($workers as $worker) {
                $this->assertFalse($worker->isRunning());
            }
        }
    }
}
