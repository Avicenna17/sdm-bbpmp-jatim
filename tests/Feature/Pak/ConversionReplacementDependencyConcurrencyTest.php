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
use App\Domain\Pak\Services\SkpImportPreparationService;
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
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\Support\Pak\SyntheticSkpSourceAdapter;
use Tests\TestCase;

class ConversionReplacementDependencyConcurrencyTest extends TestCase
{
    private PerformanceEvaluation $previous;

    private PakConversionRun $predecessor;

    private array $oldRun;

    private array $oldPeriods;

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
            fwrite(STDOUT, "\nREPLACEMENT_SKP_SERVER ".json_encode($identity)."\n");
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
        $person = Person::factory()->create(['canonical_name' => 'Synthetic SKP Conversion Subject', 'nip' => '001234567890123456']);
        $category = FunctionalCategory::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        $this->level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'PREVIEW', 'name' => 'Synthetic']);
        $position = FunctionalPosition::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        FunctionalPositionLevel::create(['functional_position_id' => $position->id, 'functional_level_id' => $this->level->id]);
        $this->rule = PakRuleSet::create(['code' => 'PREVIEW', 'version' => '1', 'name' => 'Synthetic', 'valid_from' => '2023-01-01', 'valid_until' => '2026-12-31', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        PerformancePredicateRule::create(['pak_rule_set_id' => $this->rule->id, 'predicate_code' => 'BAIK', 'predicate_name' => 'Synthetic', 'predicate_factor' => '1']);
        AkCoefficientRule::create(['pak_rule_set_id' => $this->rule->id, 'functional_level_id' => $this->level->id, 'annual_coefficient' => '25']);
        PerformancePredicateRule::create(['pak_rule_set_id' => $this->rule->id, 'predicate_code' => 'SANGAT_BAIK', 'predicate_name' => 'Synthetic very good', 'predicate_factor' => '1.5']);
        // Isolated fixture stands in for accepted case first-use lifecycle, never production preview behavior.
        DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['first_used_at' => '2026-01-01 00:00:00']);
        $this->case = PakCase::create(['person_id' => $person->id, 'pak_rule_set_id' => $this->rule->id, 'purpose_type' => 'KP', 'period_start' => '2023-01-01', 'period_end' => '2026-12-31', 'employee_data_as_of' => '2026-01-01', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $this->history = EmployeePositionHistory::create(['person_id' => $person->id, 'position_name' => 'Synthetic position', 'position_type' => 'JF', 'functional_position_id' => $position->id, 'functional_level_id' => $this->level->id,
            'tmt_start' => '2023-01-01', 'sk_number' => 'SYNTHETIC-SK-001', 'sk_date' => '2022-12-15', 'source' => 'FIXTURE', 'source_ref' => 'Synthetic documentary reference', 'verified_by' => $this->actor->id, 'verified_at' => now(), 'created_by' => $this->actor->id]);
        $this->case->refresh();
        foreach ([2023, 2024, 2025, 2026] as $year) {
            $record = $this->record($this->source($year));
            $evaluation = app(PerformanceEvaluationService::class)->createInitial($this->actor, $record);
            if ($year === 2025) {
                $this->previous = $evaluation;
            }
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
        app(FunctionalMasterService::class)->save($this->actor, 'level', [
            'code' => $this->level->code, 'name' => 'Synthetic replacement updated label',
            'functional_category_id' => $this->level->functional_category_id,
            'sort_order' => $this->level->fresh()->sort_order, 'is_active' => true,
        ], $this->level->id);
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
        $this->assertSame($this->predecessor->case_lock_version, $preview->expected_case_version);
        $request = ConversionReplaceRequest::from(['expected_predecessor_run_id' => $this->predecessor->id,
            'revision_reason' => 'Synthetic explicit replacement before SKP correction', 'pak_case_id' => $this->case->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint]);
        // Preparation after preview is documentary provenance, never a direct forged import row.
        $source = $this->source(2025, 'SANGAT_BAIK');
        $stream = $this->stream($source);
        try {
            $row = app(SkpImportPreparationService::class)->prepare($this->actor, new SyntheticSkpSourceAdapter,
                $stream, 'synthetic-correction.json', 'local')->rows()->sole();
        } finally {
            fclose($stream);
        }
        $record = $this->record($source);
        $serializer = app(SkpFingerprintSerializer::class);
        $this->assertSame($this->previous->source_identity_fingerprint, $serializer->sourceIdentityFingerprint($record));
        $this->assertSame($this->previous->source_identity_fingerprint, $row->source_identity_fingerprint);
        $this->assertSame($serializer->contentFingerprint($record), $row->content_fingerprint);
        $this->assertNotSame($this->previous->content_fingerprint, $row->content_fingerprint);
        $this->assertSame('MATCHED', $row->person_resolution_status->value);
        $this->assertSame('CONFLICT', $row->processing_status->value);

        return [$preview, $request, ['operation' => 'correct', 'predecessor' => $this->previous->id,
            'person' => $this->case->person_id, 'source' => $source, 'source_row' => $row->id]];
    }

    public static function orders(): array
    {
        return ['skp_first' => [true], 'replacement_first' => [false]];
    }

    /** SQLite validates canonical fixture/provenance and final assertions, not concurrency. */
    #[DataProvider('orders')]
    public function test_nonlive_fixture_and_serial_outcomes(bool $skpFirst): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Non-live fixture validation uses SQLite only.');
        }
        $this->fixture(false);
        [$preview, $request, $correction] = $this->intent();
        $correct = fn () => app(PerformanceEvaluationService::class)->correct($this->actor, $correction['predecessor'],
            $this->record($correction['source']), 'Synthetic concurrency correction', $correction['source_row']);
        if ($skpFirst) {
            $correct();
            try {
                app(ConversionService::class)->replace($this->actor, $request);
                $this->fail('Stale preview accepted');
            } catch (ValidationException $error) {
                $this->assertSame(['snapshot'], array_keys($error->errors()));
            }
        } else {
            app(ConversionService::class)->replace($this->actor, $request);
            $correct();
        }
        $this->assertFinalState($skpFirst, $preview, $request);
    }

    #[DataProvider('orders')]
    public function test_skp_correction_and_conversion_serialize_on_person(bool $skpFirst): void
    {
        $this->fixture(true);
        [$preview, $request, $correction] = $this->intent();
        $conversion = ['operation' => 'conversion_replace', 'request' => get_object_vars($request)];
        $operations = $skpFirst ? [$correction, $conversion] : [$conversion, $correction];
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
            $skp = $results[$skpFirst ? 0 : 1];
            $conversion = $results[$skpFirst ? 1 : 0];
            $this->assertTrue($skp['success']);
            $this->assertSame(! $skpFirst, $conversion['success']);
            if ($skpFirst) {
                $this->assertSame('VALIDATION_FAILED', $conversion['issue_code']);
                $this->assertSame(['snapshot'], $conversion['validation_fields']);
                $this->assertSame(ValidationException::class, $conversion['failure_class']);
            }
            $this->assertSame(0, $conversion['pending_callbacks']);
            $this->assertSame(0, $conversion['committed_callbacks']);
            DB::purge(); // Independent committed-state reread after both processes have exited.
            $this->assertFinalState($skpFirst, $preview, $request);
            $this->assertSame($skp['evaluation_id'], PerformanceEvaluation::where('previous_version_id', $this->previous->id)->sole()->id);
            fwrite(STDOUT, "\nREPLACEMENT_SKP_SERIALIZATION ".json_encode(['first' => $skpFirst ? 'skp' : 'replacement',
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

    private function assertFinalState(bool $skpFirst, ConversionPreview $preview, ConversionReplaceRequest $request): void
    {
        $old = $this->previous->fresh();
        $new = PerformanceEvaluation::where('previous_version_id', $old->id)->sole();
        $this->assertSame('SUPERSEDED', $old->status->value);
        $this->assertSame('CURRENT', $new->status->value);
        $this->assertSame('BAIK', $old->predicate_code);
        $this->assertSame('SANGAT_BAIK', $new->predicate_code);
        foreach (['person_id', 'source_kind', 'performance_year', 'evaluation_type', 'source_reference', 'source_record_identifier', 'source_identity_fingerprint'] as $field) {
            $this->assertSame($old->$field, $new->$field, $field);
        }
        $this->assertNotSame($old->content_fingerprint, $new->content_fingerprint);
        $this->assertSame(1, PerformanceEvaluation::where('source_identity_fingerprint', $old->source_identity_fingerprint)->where('status', 'CURRENT')->count());
        $this->assertSame(1, PakEvent::where('event_code', 'PERFORMANCE_EVALUATION_CORRECTED')->count());
        $this->assertSame('2026-01-01 00:00:00', $this->rule->fresh()->getRawOriginal('first_used_at'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame($request->expected_case_lock_version + ($skpFirst ? 0 : 1), $this->case->fresh()->lock_version);
        $this->assertSame($skpFirst ? 1 : 2, PakConversionRun::count());
        $this->assertSame($skpFirst ? 4 : 8, PakConversionPeriod::count());
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->assertSame($skpFirst ? 0 : 1, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $prior = $this->predecessor->fresh();
        $this->assertEquals($this->oldPeriods, DB::table('pak_conversion_periods')->where('pak_conversion_run_id', $prior->id)->orderBy('id')->get()->toArray());
        $this->assertSame(1, PakConversionRun::whereNotNull('active_case_id')->count());
        foreach ($this->oldRun as $field => $value) {
            if ($skpFirst || ! in_array($field, ['status', 'active_case_id', 'superseded_by_user_id', 'superseded_at', 'updated_at'], true)) {
                $this->assertSame($value, $prior->getRawOriginal($field), $field);
            }
        }
        fwrite(STDOUT, "\nREPLACEMENT_SKP_FINAL ".json_encode([
            'order' => $skpFirst ? 'skp_first' : 'replacement_first',
            'run_count' => PakConversionRun::count(), 'revisions' => PakConversionRun::orderBy('revision_number')->pluck('revision_number')->all(),
            'active_runs' => 1, 'version_before' => $request->expected_case_lock_version,
            'version_after' => $this->case->fresh()->lock_version, 'periods' => PakConversionPeriod::count(),
            'saved_events' => 1, 'replaced_events' => $skpFirst ? 0 : 1, 'corrected_events' => 1,
            'old_evaluation' => $old->id, 'new_evaluation' => $new->id,
            'old_predicate' => $old->predicate_code, 'new_predicate' => $new->predicate_code,
        ])."\n");
        if ($skpFirst) {
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
        $this->assertSame('Synthetic replacement updated label', $run->dependency_manifest['history_dependencies']['levels'][0]['name']);
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
        $period = $periods->first(fn ($p) => $p->period_start->format('Y-m-d') === '2025-01-01');
        $this->assertNotNull($period);
        $this->assertSame($old->id, $period->performance_evaluation_id);
        $this->assertNotSame($new->id, $period->performance_evaluation_id);
        $this->assertSame($old->id, $period->source_snapshot['evaluation_id']);
        $this->assertSame('BAIK', $period->source_snapshot['predicate_code']);
        $this->assertSame($old->content_fingerprint, $period->source_snapshot['content_fingerprint']);
        $this->assertSame($old->source_identity_fingerprint, $period->source_snapshot['source_identity_fingerprint']);
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
