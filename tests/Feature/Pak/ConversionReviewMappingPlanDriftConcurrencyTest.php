<?php

namespace Tests\Feature\Pak;

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

class ConversionReviewMappingPlanDriftConcurrencyTest extends TestCase
{
    private array $intentGroups;

    private array $dependencyRows;

    private int $masterEventsBefore;

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
        fwrite(STDOUT, "\nREVIEW_MAPPING_SERVER ".json_encode($identity)."\n");
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->root = storage_path('framework/testing/review-mapping-concurrency-'.bin2hex(random_bytes(8)));
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
            'code' => $this->level->code, 'name' => 'Synthetic review intent label',
            'functional_category_id' => $this->level->functional_category_id,
            'sort_order' => $this->level->fresh()->sort_order, 'is_active' => true,
        ], $this->level->id);
        $this->assertSame('Original', $this->predecessor->dependency_manifest['history_dependencies']['levels'][0]['name']);
        $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $this->masterEventsBefore = PakEvent::where('event_code', 'MASTER_SAVED')->count();
        $this->assertSame(1, $this->masterEventsBefore);
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
            'reason' => 'Synthetic review before concurrent master update',
        ]);

        $preview = app(ConversionService::class)->preview($this->actor,
            new ConversionPreviewRequest($this->case->id, '2023-11-01', '2026-10-31'));
        $this->intentGroups = $preview->dependencies->groups;
        $this->assertSame('Synthetic review intent label', $this->intentGroups['history_dependencies']['levels'][0]['name']);
        $this->assertSame($assessment->current_dependency_fingerprint, $preview->dependency_fingerprint);
        $this->assertSame($assessment->current_snapshot_fingerprint, $preview->snapshot_fingerprint);
        $this->assertSame('75/1', $preview->exact_total->getNumerator().'/'.$preview->exact_total->getDenominator());
        foreach (['functional_levels', 'ak_coefficient_rules', 'employee_position_histories', 'performance_evaluations', 'functional_categories', 'functional_positions', 'pak_rule_sets', 'performance_predicate_rules'] as $table) {
            $this->dependencyRows[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        $this->assertSame(0, FunctionalPositionLevel::count());
        $this->assertSame([], $preview->issues);

        return [$assessment, $request, ['operation' => 'master', 'type' => 'mapping',
            'master_id' => null, 'master' => ['functional_position_id' => $this->history->functional_position_id,
                'functional_level_id' => $this->level->id, 'is_active' => true]]];
    }

    public static function orders(): array
    {
        return ['after_discovery_drift' => [true], 'before_discovery_control' => [false]];
    }

    #[DataProvider('orders')]
    public function test_mapping_creation_discovery_boundary(bool $afterDiscovery): void
    {
        $this->fixture();
        [$assessment, $request, $mutation] = $this->intent();
        $this->oldCase = $this->case->fresh()->getRawOriginal();
        $this->oldAudit = DB::table('pak_events')->orderBy('id')->get()->toArray();
        $operations = [['operation' => 'conversion_review', 'request' => get_object_vars($request)], $mutation];
        $workers = [];
        foreach ($operations as $index => $operation) {
            $input = $operation + ['db' => DB::connection()->getConfig(), 'root' => $this->root,
                'actor' => $this->actor->id, 'worker' => (string) $index,
                'barrier' => $index === 0 && $afterDiscovery ? 'after_mapping_discovery' : 'none'];
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/Pak/concurrency-worker.php')], base_path(),
                ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => 'bootstrap/cache/skp-test-unused-config.php'],
                json_encode($input, JSON_THROW_ON_ERROR), 25);
        }
        try {
            $barrier = null;
            if ($afterDiscovery) {
                $workers[0]->start();
                $this->await(fn () => is_file($this->root.'/0.held'), [$workers[0]]);
                $barrier = json_decode(file_get_contents($this->root.'/0.discovery'), true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($barrier['mapping_select_completed']);
                $this->assertSame(0, $barrier['transaction_level']);
                $this->assertFalse($barrier['pdo_active']);
            }
            DB::purge();
            $observer = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertSame(0, FunctionalPositionLevel::count());
            if ($afterDiscovery) {
                $this->assertNotSame($observer, $barrier['connection_id']);
                $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=?', [$barrier['connection_id']])->n);
            }
            $workers[1]->start();
            $mapping = $this->workerResult($workers[1]);
            $this->assertTrue($mapping['success']);
            $this->assertNotSame($observer, $mapping['connection_id']);
            DB::purge(); // Mapping has committed before the review is released or even started.
            $row = FunctionalPositionLevel::sole();
            $this->assertSame($this->history->functional_position_id, $row->functional_position_id);
            $this->assertSame($this->level->id, $row->functional_level_id);
            $this->assertTrue($row->is_active);
            $this->assertSame($this->masterEventsBefore + 1, PakEvent::where('event_code', 'MASTER_SAVED')->count());
            $postMapping = app(ConversionAssessmentService::class)->assess($this->actor,
                ConversionAssessmentRequest::from(['pak_case_id' => $this->case->id, 'run_id' => $request->run_id]));
            $this->assertSame(get_object_vars($assessment), get_object_vars($postMapping));
            if ($afterDiscovery) {
                $this->assertTrue($workers[0]->isRunning());
                $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=?', [$barrier['connection_id']])->n);
                touch($this->root.'/release');
            } else {
                $this->assertFalse($workers[0]->isStarted());
                $workers[0]->start();
            }
            $review = $this->workerResult($workers[0]);
            $this->assertNotSame($review['pid'], $mapping['pid']);
            $this->assertNotContains(getmypid(), [$review['pid'], $mapping['pid']]);
            $this->assertNotSame($review['connection_id'], $mapping['connection_id']);
            $this->assertNotSame($observer, $review['connection_id']);
            $this->assertSame(0, $review['pending_callbacks']);
            $this->assertSame(0, $review['committed_callbacks']);
            $this->assertSame(! $afterDiscovery, $review['success']);
            if ($afterDiscovery) {
                $this->assertSame($barrier['pid'], $review['pid']);
                $this->assertSame($barrier['connection_id'], $review['connection_id']);
                $this->assertSame('VALIDATION_FAILED', $review['issue_code']);
                $this->assertSame(ValidationException::class, $review['failure_class']);
                $this->assertSame(['snapshot'], $review['validation_fields']);
                $this->assertSame(['snapshot' => ['Dependency lock plan changed. Obtain a new preview.']], $review['lock_plan_errors']);
                $this->assertArrayNotHasKey('run_id', $review);
                $this->assertArrayNotHasKey('assessment_sequence', $review);
            } else {
                $this->assertSame($request->run_id, $review['run_id']);
                $this->assertSame('REQUIRES_REVIEW', $review['status']);
                $this->assertSame(1, $review['assessment_sequence']);
            }
            DB::purge();
            $this->assertFinalState($afterDiscovery, $assessment, $request);
            fwrite(STDOUT, "\n".($afterDiscovery ? 'REVIEW_DISCOVERY_MAPPING_DRIFT ' : 'MAPPING_BEFORE_DISCOVERY_CONTROL ').json_encode([
                'observer' => $observer, 'barrier' => $barrier, 'review' => $review, 'mapping' => $mapping,
                'mapping_committed_before_review_release_or_start' => true,
                'mapping_count_before' => 0, 'mapping_count_after' => 1, 'mapping_id' => $row->id,
                'frozen_assessment_fingerprint' => $assessment->assessment_fingerprint,
                'post_mapping_assessment_fingerprint' => $postMapping->assessment_fingerprint,
                'dependency_fingerprint' => $assessment->current_dependency_fingerprint,
                'snapshot_fingerprint' => $assessment->current_snapshot_fingerprint,
            ])."\n");
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

    private function workerResult(Process $worker): array
    {
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), 'Worker failed; private stderr omitted.');
        $this->assertTrue($worker->getErrorOutput() === '', 'Private worker stderr omitted.');
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $result['attempts']);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertFalse($result['pdo_active']);
        $this->assertSame([], $result['diagnostics']);

        return $result;
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
                $this->fail('Discovery barrier timeout.');
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

    private function assertFinalState(bool $afterDiscovery, ConversionAssessment $before, ConversionReviewRequest $request): void
    {
        $current = FunctionalPositionLevel::sole();
        $this->assertSame($this->history->functional_position_id, $current->functional_position_id);
        $this->assertSame($this->level->id, $current->functional_level_id);
        $this->assertTrue($current->is_active);
        foreach ($this->dependencyRows as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->orderBy('id')->get()->toArray(), $table);
        }
        $this->assertNull($this->history->fresh()->superseded_at);
        $this->assertSame($this->masterEventsBefore + 1, PakEvent::where('event_code', 'MASTER_SAVED')->count());
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
        $this->assertSame('Original', $run->dependency_manifest['history_dependencies']['levels'][0]['name']);
        $allowed = $afterDiscovery ? [] : ['status', 'review_assessment', 'review_assessed_by', 'review_assessed_at', 'updated_at'];
        $this->assertSame(array_diff_key($this->oldRun, array_flip($allowed)), array_diff_key($run->getRawOriginal(), array_flip($allowed)));
        $this->assertSame($afterDiscovery ? 'PREVIEW' : 'REQUIRES_REVIEW', $run->status->value);
        if ($afterDiscovery) {
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
        $this->assertCount(count($this->oldAudit) + ($afterDiscovery ? 1 : 2), $audit);
        $this->assertEquals($this->oldAudit, array_slice($audit, 0, count($this->oldAudit)));
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $this->assertSame($afterDiscovery ? 0 : 1, PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->count());
        $masterEvent = PakEvent::where('event_code', 'MASTER_SAVED')->orderByDesc('id')->firstOrFail();
        $this->assertSame('FunctionalPositionLevel', $masterEvent->subject_type);
        $this->assertSame($current->id, $masterEvent->subject_id);
        if (! $afterDiscovery) {
            $review = PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->sole();
            $this->assertSame('PakConversionRun', $review->subject_type);
            $this->assertSame($run->id, $review->subject_id);
            $this->assertSame($this->case->id, $review->pak_case_id);
            $this->assertSame($this->actor->id, $review->actor_id);
            $this->assertSame(1, $review->payload['after']['assessment_sequence']);
            $this->assertGreaterThan($masterEvent->id, $review->id);
            $this->assertTrue($run->review_assessed_at->lessThanOrEqualTo($review->occurred_at));
        }
        $rawRun = $run->getRawOriginal();
        $fresh = app(ConversionAssessmentService::class)->assess($this->actor,
            ConversionAssessmentRequest::from(['pak_case_id' => $this->case->id, 'run_id' => $run->id]));
        $this->assertSame('CHANGED', $fresh->comparison);
        $this->assertSame([], $fresh->current_issue_codes);
        $this->assertSame($request->expected_case_lock_version, $fresh->observed_case_lock_version);
        $this->assertSame($afterDiscovery ? 0 : 1, $fresh->assessment_sequence);
        $this->assertSame($run->status->value, $fresh->observed_run_status);
        // Mapping identity is coordination-only, never semantic assessment drift.
        if ($afterDiscovery) {
            $this->assertSame(get_object_vars($before), get_object_vars($fresh));
        } else {
            $this->assertNotSame($before->assessment_fingerprint, $fresh->assessment_fingerprint);
        }
        $this->assertSame($before->current_dependency_fingerprint, $fresh->current_dependency_fingerprint);
        $this->assertSame($before->current_snapshot_fingerprint, $fresh->current_snapshot_fingerprint);
        $this->assertContains('history_dependencies', $fresh->changed_groups);
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
        fwrite(STDOUT, "\n".($afterDiscovery ? 'REVIEW_DISCOVERY_MAPPING_DRIFT_FINAL ' : 'MAPPING_BEFORE_DISCOVERY_FINAL ').json_encode([
            'run_id' => $run->id, 'run_count' => 1, 'run_status' => $run->status->value,
            'review_sequence' => $fresh->assessment_sequence, 'case_version_before' => $request->expected_case_lock_version,
            'case_version_after' => $this->case->fresh()->lock_version, 'saved_events' => 1,
            'review_events' => $afterDiscovery ? 0 : 1, 'replacement_events' => 0, 'concurrent_master_events' => 1,
            'mapping_id' => $current->id, 'mapping_position' => $current->functional_position_id, 'mapping_level' => $current->functional_level_id,
            'master_events_before' => $this->masterEventsBefore, 'master_events_after' => $this->masterEventsBefore + 1,
            'periods_unchanged' => true,
            'saved_total' => '75/1', 'fresh_total' => '75/1', 'fresh_dependency_equal' => true,
            'fresh_snapshot_equal' => true, 'assessment_token_equal' => $afterDiscovery,
            'remaining_innodb_transactions' => 0,
        ])."\n");
    }
}
