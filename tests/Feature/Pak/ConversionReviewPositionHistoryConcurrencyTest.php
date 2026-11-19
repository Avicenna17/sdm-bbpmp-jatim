<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionAssessment;
use App\Domain\Pak\DTOs\ConversionAssessmentRequest;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionReviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\DTOs\NormalizedSkpRecord;
use App\Domain\Pak\Services\ConversionAssessmentService;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\EmployeeHistoryService;
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

class ConversionReviewPositionHistoryConcurrencyTest extends TestCase
{
    private FunctionalLevel $newLevel;

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
        fwrite(STDOUT, "\nREVIEW_POSITION_SERVER ".json_encode($identity)."\n");
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->root = storage_path('framework/testing/review-position-concurrency-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0700, true);
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $this->root, 'visibility' => 'private', 'throw' => false]]);
        Storage::forgetDisk('local');
        $this->actor = User::factory()->create(['is_active' => true]);
        foreach (['pak.access', 'pak.employee.manage', 'pak.skp.manage', 'pak.master.manage', ...ConversionService::PERMISSIONS] as $permission) {
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
        // This isolates C.4 history drift from C.7 mapping-plan drift.
        app(EmployeeHistoryService::class)->record($this->actor, $person,
            array_replace($this->correctionInput(), ['tmt_start' => '2021-01-01', 'tmt_end' => '2022-12-31']), true);
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
        foreach ($this->oldPeriods as $period) {
            $this->assertSame($this->history->id, $period->employee_position_history_id);
            $this->assertSame($this->oldCoefficient->id, $period->ak_coefficient_rule_id);
        }
        app(FunctionalMasterService::class)->save($this->actor, 'level', [
            'code' => $this->level->code, 'name' => 'Synthetic review dependency change',
            'functional_category_id' => $this->level->functional_category_id,
            'sort_order' => $this->level->fresh()->sort_order, 'is_active' => true,
        ], $this->level->id);
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
            'reason' => 'Synthetic review before history correction',
        ]);

        return [$assessment, $request, ['operation' => 'history', 'person' => $this->case->person_id,
            'history' => $this->correctionInput(), 'supersedes_id' => $this->history->id,
            'reason' => 'Synthetic position documentary correction']];
    }

    public static function orders(): array
    {
        return ['review_first' => [false], 'history_first' => [true]];
    }

    #[DataProvider('orders')]
    public function test_history_correction_and_conversion_serialize_on_person(bool $historyFirst): void
    {
        $this->fixture();
        [$assessment, $request, $correction] = $this->intent();
        $this->oldCase = $this->case->fresh()->getRawOriginal();
        $this->oldAudit = DB::table('pak_events')->orderBy('id')->get()->toArray();
        $this->assertNull($this->history->fresh()->superseded_at);
        $this->assertSame($this->level->id, $this->history->fresh()->functional_level_id);
        $conversion = ['operation' => 'conversion_review', 'request' => get_object_vars($request)];
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
            $historyResult = $results[$historyFirst ? 0 : 1];
            $conversion = $results[$historyFirst ? 1 : 0];
            $this->assertTrue($historyResult['success']);
            $this->assertSame(! $historyFirst, $conversion['success']);
            if ($historyFirst) {
                $this->assertSame('VALIDATION_FAILED', $conversion['issue_code']);
                $this->assertSame(['expected_assessment_fingerprint'], $conversion['validation_fields']);
                $this->assertSame(ValidationException::class, $conversion['failure_class']);
                $this->assertArrayNotHasKey('run_id', $conversion);
                $this->assertArrayNotHasKey('assessment_sequence', $conversion);
            } else {
                $this->assertSame($request->run_id, $conversion['run_id']);
                $this->assertSame('REQUIRES_REVIEW', $conversion['status']);
                $this->assertSame(1, $conversion['assessment_sequence']);
            }
            $this->assertSame(0, $conversion['pending_callbacks']);
            $this->assertSame(0, $conversion['committed_callbacks']);
            DB::purge(); // Independent committed-state reread after both processes have exited.
            $this->assertFinalState($historyFirst, $assessment, $request);
            $this->assertSame($historyResult['history_id'], EmployeePositionHistory::where('supersedes_id', $this->history->id)->sole()->id);
            fwrite(STDOUT, "\n".($historyFirst ? 'POSITION_FIRST_REVIEW_SERIALIZATION ' : 'REVIEW_FIRST_POSITION_SERIALIZATION ').json_encode(['first' => $historyFirst ? 'history' : 'review',
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

    private function assertFinalState(bool $historyFirst, ConversionAssessment $before, ConversionReviewRequest $request): void
    {
        $old = $this->history->fresh();
        $new = EmployeePositionHistory::where('supersedes_id', $old->id)->sole();
        $this->assertNotNull($old->superseded_at);
        $this->assertSame($this->level->id, $old->functional_level_id);
        $this->assertSame('SYNTHETIC-SK-001', $old->sk_number);
        $this->assertNull($new->superseded_at);
        $this->assertSame($old->id, $new->supersedes_id);
        $this->assertSame($old->person_id, $new->person_id);
        $this->assertSame($old->functional_position_id, $new->functional_position_id);
        $this->assertSame($this->newLevel->id, $new->functional_level_id);
        $this->assertSame('2023-01-01', $new->tmt_start->format('Y-m-d'));
        $this->assertSame('SYNTHETIC-CORRECTED-SK', $new->sk_number);
        $this->assertSame('2022-12-20', $new->sk_date->format('Y-m-d'));
        $this->assertSame('Synthetic corrected documentary reference', $new->source_ref);
        $this->assertSame($this->actor->id, $new->verified_by);
        $this->assertNotNull($new->verified_at);
        $this->assertSame(1, EmployeePositionHistory::where('person_id', $old->person_id)->whereDate('tmt_start', '2023-01-01')->whereNull('superseded_at')->count());
        $this->assertSame(3, EmployeePositionHistory::count());
        $this->assertSame('2026-01-01 00:00:00', $this->rule->fresh()->getRawOriginal('first_used_at'));
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
        $allowed = $historyFirst ? [] : ['status', 'review_assessment', 'review_assessed_by', 'review_assessed_at', 'updated_at'];
        $this->assertSame(array_diff_key($this->oldRun, array_flip($allowed)), array_diff_key($run->getRawOriginal(), array_flip($allowed)));
        $this->assertSame($historyFirst ? 'PREVIEW' : 'REQUIRES_REVIEW', $run->status->value);
        if ($historyFirst) {
            $this->assertNull($run->review_assessment);
            $this->assertNull($run->review_assessed_by);
            $this->assertNull($run->review_assessed_at);
        } else {
            // This is the exact pre-correction assessment, not a recomputation after the writer.
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
        }
        $audit = DB::table('pak_events')->orderBy('id')->get()->toArray();
        $this->assertCount(count($this->oldAudit) + ($historyFirst ? 1 : 2), $audit);
        $this->assertEquals($this->oldAudit, array_slice($audit, 0, count($this->oldAudit)));
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $this->assertSame($historyFirst ? 0 : 1, PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->count());
        $corrected = PakEvent::where('event_code', 'HISTORY_CORRECTED')->sole();
        $this->assertSame('EmployeePositionHistory', $corrected->subject_type);
        $this->assertSame($new->id, $corrected->subject_id);
        if (! $historyFirst) {
            $review = PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->sole();
            $this->assertSame('PakConversionRun', $review->subject_type);
            $this->assertSame($run->id, $review->subject_id);
            $this->assertSame($this->case->id, $review->pak_case_id);
            $this->assertSame($this->actor->id, $review->actor_id);
            $this->assertSame(1, $review->payload['after']['assessment_sequence']);
            $this->assertLessThan($corrected->id, $review->id);
            $this->assertTrue($run->review_assessed_at->lessThanOrEqualTo($review->occurred_at));
        }
        $rawRun = $run->getRawOriginal();
        $fresh = app(ConversionAssessmentService::class)->assess($this->actor,
            ConversionAssessmentRequest::from(['pak_case_id' => $this->case->id, 'run_id' => $run->id]));
        $this->assertSame('CHANGED', $fresh->comparison);
        $this->assertSame([], $fresh->current_issue_codes);
        $this->assertSame($request->expected_case_lock_version, $fresh->observed_case_lock_version);
        $this->assertSame($historyFirst ? 0 : 1, $fresh->assessment_sequence);
        $this->assertSame($run->status->value, $fresh->observed_run_status);
        // Hash drift is semantic evidence, independently of the lifecycle sequence in the token.
        $this->assertNotSame($before->assessment_fingerprint, $fresh->assessment_fingerprint);
        $this->assertNotSame($before->current_dependency_fingerprint, $fresh->current_dependency_fingerprint);
        $this->assertNotSame($before->current_snapshot_fingerprint, $fresh->current_snapshot_fingerprint);
        $this->assertContains('history_dependencies', $fresh->changed_groups);
        $preview = app(ConversionService::class)->preview($this->actor,
            new ConversionPreviewRequest($this->case->id, '2023-11-01', '2026-10-31'));
        $this->assertSame([], $preview->issues);
        $this->assertSame('225/2', (string) $preview->exact_total);
        $this->assertSame($fresh->current_dependency_fingerprint, $preview->dependency_fingerprint);
        $this->assertSame($fresh->current_snapshot_fingerprint, $preview->snapshot_fingerprint);
        foreach ($preview->lines as $line) {
            $this->assertSame($new->id, $line->position->history_id);
            $this->assertSame($this->newLevel->id, $line->position->functional_level_id);
            $this->assertSame('75/2', (string) BigRational::of($line->coefficient->annualCoefficient)->simplified());
        }
        $this->assertSame($rawRun, $run->fresh()->getRawOriginal());
        $this->assertEquals($audit, DB::table('pak_events')->orderBy('id')->get()->toArray());
        $this->assertEquals($this->oldPeriods, DB::table('pak_conversion_periods')->orderBy('id')->get()->toArray());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertCount(0, app('db.transactions')->getPendingTransactions());
        $this->assertCount(0, app('db.transactions')->getCommittedTransactions());
        $this->await(fn () => (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX')->n === 0);
        fwrite(STDOUT, "\n".($historyFirst ? 'POSITION_FIRST_REVIEW_FINAL ' : 'REVIEW_FIRST_POSITION_FINAL ').json_encode([
            'run_id' => $run->id, 'run_count' => 1, 'run_status' => $run->status->value,
            'review_sequence' => $fresh->assessment_sequence, 'case_version_before' => $request->expected_case_lock_version,
            'case_version_after' => $this->case->fresh()->lock_version, 'saved_events' => 1,
            'review_events' => $historyFirst ? 0 : 1, 'replacement_events' => 0, 'corrected_events' => 1,
            'old_history' => $old->id, 'new_history' => $new->id, 'old_superseded' => true, 'new_superseded' => false,
            'old_level' => $old->functional_level_id, 'new_level' => $new->functional_level_id, 'periods_unchanged' => true,
            'saved_total' => '75/1', 'fresh_total' => '225/2', 'fresh_dependency_changed' => true,
            'fresh_snapshot_changed' => true, 'fresh_assessment_token_changed' => true,
            'remaining_innodb_transactions' => 0,
        ])."\n");
    }
}
