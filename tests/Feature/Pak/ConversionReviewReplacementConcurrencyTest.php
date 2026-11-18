<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionAssessmentRequest;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionReplaceRequest;
use App\Domain\Pak\DTOs\ConversionReviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\Services\ConversionAssessmentService;
use App\Domain\Pak\Services\ConversionManifestBuilder;
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

class ConversionReviewReplacementConcurrencyTest extends TestCase
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
        fwrite(STDOUT, "\nREVIEW_REPLACE_SERVER ".json_encode($identity)."\n");
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->root = storage_path('framework/testing/review-replace-concurrency-'.bin2hex(random_bytes(8)));
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

    private function worker(string $name, ConversionReviewRequest|ConversionReplaceRequest $request, bool $hold): Process
    {
        $input = ['operation' => $request instanceof ConversionReviewRequest ? 'conversion_review' : 'conversion_replace', 'request' => get_object_vars($request),
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
        $this->assertTrue($worker->getErrorOutput() === '', 'Unexpected worker stderr; private details omitted.');
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

    public static function orders(): array
    {
        return ['review_first' => [true], 'replacement_first' => [false]];
    }

    #[DataProvider('orders')]
    public function test_review_and_replacement_serialize_on_exact_case(bool $reviewFirst): void
    {
        // Both immutable requests are frozen before either writer starts.
        $review = $this->reviewRequest();
        $preview = $this->preview();
        $replace = ConversionReplaceRequest::from([
            'pak_case_id' => $this->case->id, 'expected_predecessor_run_id' => $this->predecessor->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint,
            'revision_reason' => 'Synthetic explicit replacement after dependency change',
        ]);
        $this->assertSame('PREVIEW', $review->expected_run_status);
        $this->assertSame(0, $review->expected_assessment_sequence);
        $this->assertSame($review->expected_case_lock_version, $replace->expected_case_lock_version);
        $this->assertNotSame($this->predecessor->dependency_fingerprint, $preview->dependency_fingerprint);
        $this->assertNotSame($this->predecessor->snapshot_fingerprint, $preview->snapshot_fingerprint);
        $this->assertSame([], $preview->issues);
        $this->assertSame(1, PakConversionRun::count());
        $this->oldRun = $this->predecessor->fresh()->getRawOriginal();
        $this->oldPeriods = DB::table('pak_conversion_periods')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $oldAudit = DB::table('pak_events')->orderBy('id')->get()->toArray();
        $marker = $this->rule->fresh()->getRawOriginal('first_used_at');
        $requests = $reviewFirst ? [$review, $replace] : [$replace, $review];
        $workers = [$this->worker('a', $requests[0], true), $this->worker('b', $requests[1], false)];
        $label = $reviewFirst ? 'REVIEW_FIRST' : 'REPLACEMENT_FIRST';
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
            $reviewResult = $results[$reviewFirst ? 0 : 1];
            $replaceResult = $results[$reviewFirst ? 1 : 0];
            $this->assertTrue($replaceResult['success']);
            $this->assertSame($reviewFirst, $reviewResult['success']);
            if ($reviewFirst) {
                $this->assertSame($review->run_id, $reviewResult['run_id']);
                $this->assertSame('REQUIRES_REVIEW', $reviewResult['status']);
                $this->assertSame(1, $reviewResult['assessment_sequence']);
            } else {
                $this->assertSame('VALIDATION_FAILED', $reviewResult['issue_code']);
                $this->assertSame(ValidationException::class, $reviewResult['failure_class']);
                $this->assertSame(['case'], $reviewResult['validation_fields']);
                $this->assertArrayNotHasKey('run_id', $reviewResult);
                $this->assertArrayNotHasKey('assessment_sequence', $reviewResult);
            }
            fwrite(STDOUT, "\n".$label.'_SERIALIZATION '.json_encode([
                'observer' => $observer, 'wait' => $wait, 'workers' => $results,
                'operations' => $reviewFirst ? ['review', 'replacement'] : ['replacement', 'review'],
            ])."\n");

            DB::purge(); // Reread only committed state after both independent processes exited.
            $runs = PakConversionRun::orderBy('revision_number')->get();
            $this->assertCount(2, $runs);
            $this->assertSame([1, 2], $runs->pluck('revision_number')->all());
            [$old, $successor] = $runs->all();
            $case = $this->case->fresh();
            $this->assertSame($review->run_id, $old->id);
            $this->assertSame('SUPERSEDED', $old->status->value);
            $this->assertNull($old->active_case_id);
            $this->assertSame($this->actor->id, $old->superseded_by_user_id);
            $this->assertNotNull($old->superseded_at);
            $allowed = ['status', 'active_case_id', 'superseded_by_user_id', 'superseded_at', 'updated_at'];
            if ($reviewFirst) {
                $allowed = [...$allowed, 'review_assessment', 'review_assessed_by', 'review_assessed_at'];
            }
            $this->assertSame(array_diff_key($this->oldRun, array_flip($allowed)), array_diff_key($old->getRawOriginal(), array_flip($allowed)));
            $this->assertSame($this->oldPeriods, DB::table('pak_conversion_periods')->where('pak_conversion_run_id', $old->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
            if ($reviewFirst) {
                $this->assertSame([
                    'schema_version' => 1, 'assessment_sequence' => 1, 'comparison' => 'CHANGED',
                    'assessment_fingerprint' => $review->expected_assessment_fingerprint,
                    'current_dependency_fingerprint' => $preview->dependency_fingerprint,
                    'current_snapshot_fingerprint' => $preview->snapshot_fingerprint,
                    'changed_groups' => ['history_dependencies'], 'current_issue_codes' => [],
                    'failure_code' => null, 'reason' => $review->reason,
                ], $old->review_assessment);
                $this->assertSame($this->actor->id, $old->review_assessed_by);
                $this->assertNotNull($old->review_assessed_at);
            } else {
                $this->assertNull($old->review_assessment);
                $this->assertNull($old->review_assessed_by);
                $this->assertNull($old->review_assessed_at);
            }
            // No review retarget or inheritance onto the new authoritative revision.
            $this->assertNull($successor->review_assessment);
            $this->assertNull($successor->review_assessed_by);
            $this->assertNull($successor->review_assessed_at);
            $this->assertSame($replaceResult['run_id'], $successor->id);
            $this->assertSame('PREVIEW', $successor->status->value);
            $this->assertSame($old->id, $successor->predecessor_run_id);
            $this->assertSame($replace->revision_reason, $successor->revision_reason);
            $this->assertSame($case->id, $successor->active_case_id);
            $this->assertNull($successor->superseded_at);
            $this->assertNull($successor->superseded_by_user_id);
            $this->assertSame(1, PakConversionRun::whereNotNull('active_case_id')->count());
            $this->assertSame(1, PakConversionRun::where('predecessor_run_id', $old->id)->count());
            $this->assertSame($replace->expected_case_lock_version + 1, $case->lock_version);
            $this->assertSame($case->lock_version, $successor->case_lock_version);
            $this->assertSame($case->person_id, $successor->person_id);
            $this->assertSame($case->pak_rule_set_id, $successor->pak_rule_set_id);
            $this->assertSame($replace->expected_dependency_fingerprint, $successor->dependency_fingerprint);
            $this->assertSame($replace->expected_snapshot_fingerprint, $successor->snapshot_fingerprint);
            $this->assertSame(ConversionManifestBuilder::canonical($preview->dependencies->groups), ConversionManifestBuilder::canonical($successor->dependency_manifest));
            $this->assertSame($successor->dependency_fingerprint, ConversionManifestBuilder::hash($successor->dependency_manifest));
            $this->assertSame('75/1', $successor->total_numerator.'/'.$successor->total_denominator);
            $this->assertSame(8, PakConversionPeriod::count());
            $periods = $successor->periods()->orderBy('line_number')->get();
            $this->assertCount(4, $periods);
            $this->assertSame(['25/6', '25/1', '25/1', '125/6'], $periods->map(fn ($p) => $p->result_numerator.'/'.$p->result_denominator)->all());
            foreach ($periods as $period) {
                $this->assertSame($this->history->id, $period->employee_position_history_id);
                $this->assertSame('Synthetic review dependency change', $period->position_snapshot['functional_level_name']);
            }
            $this->assertSame($marker, $this->rule->fresh()->getRawOriginal('first_used_at'));
            $audit = DB::table('pak_events')->orderBy('id')->get()->toArray();
            $this->assertCount(count($oldAudit) + ($reviewFirst ? 2 : 1), $audit);
            $this->assertEquals($oldAudit, array_slice($audit, 0, count($oldAudit)));
            $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
            $this->assertSame($reviewFirst ? 1 : 0, PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->count());
            $replacementEvent = PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->sole();
            $this->assertSame('PakConversionRun', $replacementEvent->subject_type);
            $this->assertSame($successor->id, $replacementEvent->subject_id);
            $this->assertSame($case->id, $replacementEvent->pak_case_id);
            $this->assertSame($old->id, $replacementEvent->payload['supersedes_id']);
            if ($reviewFirst) {
                $reviewEvent = PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->sole();
                $this->assertSame('PakConversionRun', $reviewEvent->subject_type);
                $this->assertSame($old->id, $reviewEvent->subject_id);
                $this->assertSame($case->id, $reviewEvent->pak_case_id);
                $this->assertSame($this->actor->id, $reviewEvent->actor_id);
                $this->assertSame('REQUIRES_REVIEW', $replacementEvent->payload['before']['status']);
                $this->assertLessThan($replacementEvent->id, $reviewEvent->id);
                $this->assertTrue($old->review_assessed_at->lessThanOrEqualTo($reviewEvent->occurred_at));
            }
            $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->where('subject_id', $successor->id)->count());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->await(fn () => (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX')->n === 0);
            foreach ($workers as $worker) {
                $this->assertFalse($worker->isRunning());
            }
            fwrite(STDOUT, "\n".$label.'_FINAL '.json_encode([
                'revisions' => [1, 2], 'predecessor_id' => $old->id, 'successor_id' => $successor->id,
                'predecessor_status' => $old->status->value, 'predecessor_review_sequence' => $old->review_assessment['assessment_sequence'] ?? 0,
                'successor_status' => $successor->status->value, 'successor_review' => null,
                'case_version_before' => $review->expected_case_lock_version, 'case_version_after' => $case->lock_version,
                'saved_audits' => 1, 'review_audits' => $reviewFirst ? 1 : 0, 'replacement_audits' => 1,
                'period_count' => 8, 'old_periods_unchanged' => true, 'active_count' => 1, 'total' => '75/1',
                'remaining_workers' => 0, 'remaining_innodb_transactions' => 0,
            ])."\n");
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
