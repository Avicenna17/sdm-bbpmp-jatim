<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\NormalizedSkpRecord;
use App\Domain\Pak\Exceptions\SkpCommitException;
use App\Domain\Pak\Exceptions\SkpSourceStorageException;
use App\Domain\Pak\Services\PerformanceEvaluationService;
use App\Domain\Pak\Services\SkpCommitRetryPolicy;
use App\Domain\Pak\Services\SkpFingerprintSerializer;
use App\Domain\Pak\Services\SkpImportCommitService;
use App\Domain\Pak\Services\SkpImportPreparationService;
use App\Models\PakEvent;
use App\Models\PakSkpImportBatch;
use App\Models\PakSkpImportRow;
use App\Models\PerformanceEvaluation;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Pak\SyntheticSkpSourceAdapter;
use Tests\TestCase;

class SkpImportCommitTest extends TestCase
{
    // Real outer transactions are required by the commit retry contract; no enclosing test transaction.

    private User $actor;

    private Person $person;

    private SkpImportCommitService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Commit core tests require isolated in-memory SQLite.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
        Storage::fake('local');
        $this->actor = User::factory()->create(['is_active' => true]);
        foreach (['pak.access', 'pak.skp.manage'] as $name) {
            $this->actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->person = Person::factory()->create(['nip' => '001234567890123456']);
        $this->service = app(SkpImportCommitService::class);
    }

    private function raw(array $changes = []): array
    {
        return array_replace(['nip' => $this->person->nip, 'source_kind' => 'OFFICIAL', 'evaluation_type' => 'ANNUAL',
            'performance_year' => 2025, 'source_reference' => 'SYNTHETIC/001', 'source_record_identifier' => 'REC-001',
            'predicate_code' => 'BAIK', 'predicate_label_original' => 'Baik', 'coverage_start' => '2025-01-01', 'coverage_end' => '2025-12-31',
            'raw_provenance' => ['private' => 'PRIVATE_SENTINEL']], $changes);
    }

    private function stream(array $rows): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, json_encode($rows, JSON_THROW_ON_ERROR));
        rewind($stream);

        return $stream;
    }

    private function prepare(array $changes = [[]]): PakSkpImportBatch
    {
        $stream = $this->stream(array_map(fn ($change) => $this->raw($change), $changes));
        try {
            return app(SkpImportPreparationService::class)->prepare($this->actor, new SyntheticSkpSourceAdapter, $stream, 'synthetic.json', 'local');
        } finally {
            fclose($stream);
        }
    }

    private function record(array $changes = []): NormalizedSkpRecord
    {
        $stream = $this->stream([$this->raw($changes)]);
        try {
            $source = iterator_to_array((new SyntheticSkpSourceAdapter)->read($stream))[0];

            return app(SkpFingerprintSerializer::class)->normalize($source, $this->person->id);
        } finally {
            fclose($stream);
        }
    }

    private function failCode(callable $action, string $code): void
    {
        try {
            $action();
            $this->fail('Expected safe failure '.$code);
        } catch (SkpCommitException $error) {
            $this->assertSame($code, $error->issue_code);
            $this->assertSame($code, $error->getMessage());
        }
    }

    private function domainSnapshot(): array
    {
        return collect([PakSkpImportBatch::class, PakSkpImportRow::class, PerformanceEvaluation::class, PakEvent::class])
            ->mapWithKeys(fn ($model) => [$model => $model::query()->orderBy('id')->get()->map(fn ($record) => $record->getAttributes())->all()])
            ->all();
    }

    public function test_outer_transaction_is_rejected_before_filesystem_or_domain_work(): void
    {
        $batch = $this->prepare();
        app(PerformanceEvaluationService::class)->createInitial($this->actor, $this->record());
        $before = $this->domainSnapshot();
        Storage::shouldReceive('disk')->never();
        $nestedTransactions = 0;

        DB::transaction(function () use ($batch, $before, &$nestedTransactions) {
            DB::connection()->beforeStartingTransaction(function () use (&$nestedTransactions) {
                $nestedTransactions++;
            });
            $this->assertSame(1, DB::transactionLevel());
            $this->failCode(fn () => $this->service->commit($this->actor, $batch->id), 'COMMIT_REQUIRES_TOP_LEVEL_TRANSACTION');
            $this->assertSame(1, DB::transactionLevel());
            // Check inside the outer transaction so rollback cannot hide attempted domain writes.
            $this->assertSame($before, $this->domainSnapshot());
        });

        $this->assertSame(0, $nestedTransactions);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->domainSnapshot());
        $this->assertDatabaseCount('pak_events', 0);
    }

    public static function nonReadyStatuses(): array
    {
        return [['PARSING'], ['INVALID'], ['FAILED']];
    }

    #[DataProvider('nonReadyStatuses')]
    public function test_non_ready_batch_is_rejected_without_filesystem_or_domain_work(string $status): void
    {
        $batch = $this->prepare();
        $batch->update(['status' => $status]);
        $before = $this->domainSnapshot();
        Storage::shouldReceive('disk')->never();

        $this->failCode(fn () => $this->service->commit($this->actor, $batch->id), 'COMMIT_NOT_ALLOWED');

        $this->assertSame($before, $this->domainSnapshot());
        $this->assertSame($status, $batch->fresh()->status->value);
        $this->assertDatabaseCount('performance_evaluations', 0);
        $this->assertDatabaseCount('pak_events', 0);
    }

    public function test_new_equivalent_occurrences_commit_once_with_safe_audit_and_idempotent_fast_path(): void
    {
        $batch = $this->prepare([[], []]);
        $rawBefore = $batch->rows()->orderBy('record_ordinal')->get()->map(fn ($row) => $row->raw_provenance)->all();
        $final = $this->service->commit($this->actor, $batch->id);
        $rows = $final->rows()->orderBy('record_ordinal')->get();
        $this->assertSame(['IMPORTED', 'DUPLICATE'], $rows->map(fn ($row) => $row->processing_status->value)->all());
        $this->assertSame($rows[0]->performance_evaluation_id, $rows[1]->performance_evaluation_id);
        $this->assertDatabaseCount('performance_evaluations', 1);
        $this->assertSame('CURRENT', PerformanceEvaluation::sole()->status->value);
        $this->assertSame('COMMITTED', $final->status->value);
        $this->assertSame($this->actor->id, $final->committed_by);
        $this->assertNotNull($final->committed_at);
        $this->assertSame(1, $final->summary['outcomes']['IMPORTED']);
        $this->assertSame(1, $final->summary['outcomes']['DUPLICATE']);
        $this->assertSame($rawBefore, $rows->map(fn ($row) => $row->raw_provenance)->all());
        $event = PakEvent::sole();
        $this->assertSame('SKP_IMPORT_COMMITTED', $event->event_code);
        $this->assertSame('PakSkpImportBatch', $event->subject_type);
        $this->assertSame($batch->id, $event->subject_id);
        $this->assertSame(['before' => ['id' => $batch->id, 'status' => 'READY'],
            'after' => ['id' => $batch->id, 'status' => 'COMMITTED', 'summary' => $final->summary]], $event->payload);
        foreach ([$this->person->nip, 'PRIVATE_SENTINEL', $batch->object_key, 'normalized_payload'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($event->payload));
        }
        $before = $final->getAttributes();
        $rowBefore = $rows->map(fn ($row) => $row->getAttributes())->all();
        // Idempotent return must not need source availability or perform filesystem work.
        Storage::disk('local')->delete($batch->object_key);
        $again = $this->service->commit($this->actor, $batch->id);
        $this->assertSame($before, $again->getAttributes());
        $this->assertSame($rowBefore, $again->rows()->orderBy('record_ordinal')->get()->map(fn ($row) => $row->getAttributes())->all());
        $this->assertDatabaseCount('pak_events', 1);
        $this->assertDatabaseCount('performance_evaluations', 1);
    }

    public function test_representative_uses_ordinal_not_database_insertion_order(): void
    {
        $template = $this->prepare([[], []]);
        $batch = $template->replicate();
        $batch->status = 'PARSING';
        $batch->save();
        foreach ($template->rows()->orderByDesc('record_ordinal')->get() as $row) {
            $copy = $row->replicate();
            $copy->batch_id = $batch->id;
            $copy->save();
        }
        $batch->update(['status' => 'READY']);
        $this->service->commit($this->actor, $batch->id);
        $this->assertSame(['DUPLICATE', 'IMPORTED'], $batch->rows()->orderBy('id')->get()->map(fn ($row) => $row->processing_status->value)->all());
    }

    public static function currentContents(): array
    {
        return [['BAIK', 'DUPLICATE'], ['CUKUP', 'CONFLICT']];
    }

    #[DataProvider('currentContents')]
    public function test_new_current_after_preparation_is_authoritative(string $predicate, string $outcome): void
    {
        $batch = $this->prepare([[], []]);
        $evaluation = app(PerformanceEvaluationService::class)->createInitial($this->actor, $this->record(['predicate_code' => $predicate]));
        $before = $evaluation->fresh()->getAttributes();
        $this->service->commit($this->actor, $batch->id);
        foreach ($batch->rows as $row) {
            $this->assertSame($outcome, $row->processing_status->value);
            $this->assertSame($outcome === 'DUPLICATE' ? $evaluation->id : null, $row->performance_evaluation_id);
        }
        $this->assertSame($before, $evaluation->fresh()->getAttributes());
        $this->assertDatabaseCount('performance_evaluations', 1);
    }

    public function test_old_duplicate_link_is_ignored_after_correction(): void
    {
        $old = app(PerformanceEvaluationService::class)->createInitial($this->actor, $this->record());
        $batch = $this->prepare();
        $evidence = $this->prepare([['predicate_code' => 'CUKUP']])->rows()->sole();
        $next = app(PerformanceEvaluationService::class)->correct($this->actor, $old->id, $this->record(['predicate_code' => 'CUKUP']), 'Correction', $evidence->id);
        $this->assertSame($old->id, $batch->rows()->sole()->performance_evaluation_id);
        $this->service->commit($this->actor, $batch->id);
        $this->assertSame('CONFLICT', $batch->rows()->sole()->processing_status->value);
        $this->assertNull($batch->rows()->sole()->performance_evaluation_id);
        $this->assertSame('CURRENT', $next->fresh()->status->value);
        $this->assertDatabaseCount('performance_evaluations', 2);
    }

    public function test_different_content_same_identity_has_no_winner_without_current(): void
    {
        $batch = $this->prepare([[], ['predicate_code' => 'CUKUP']]);
        $this->service->commit($this->actor, $batch->id);
        foreach ($batch->rows as $row) {
            $this->assertSame('CONFLICT', $row->processing_status->value);
            $this->assertNull($row->performance_evaluation_id);
        }
        $this->assertDatabaseCount('performance_evaluations', 0);
    }

    public function test_mixed_non_candidates_are_retained_without_ready_or_pending_after_closure(): void
    {
        $batch = $this->prepare([[], ['nip' => null], ['source_record_identifier' => null]]);
        $before = $batch->rows()->where('record_ordinal', '>', 1)->orderBy('record_ordinal')->get()->map(fn ($row) => $row->getAttributes())->all();
        $this->service->commit($this->actor, $batch->id);
        $this->assertSame($before, $batch->rows()->where('record_ordinal', '>', 1)->orderBy('record_ordinal')->get()->map(fn ($row) => $row->getAttributes())->all());
        $this->assertSame(['IMPORTED', 'INVALID', 'IDENTITY_AMBIGUOUS'], $batch->rows()->orderBy('record_ordinal')->get()->map(fn ($row) => $row->processing_status->value)->all());
        $this->assertSame(0, $batch->fresh()->summary['outcomes']['READY']);
        $this->assertSame(0, $batch->fresh()->summary['outcomes']['PENDING']);
    }

    public static function corruptions(): array
    {
        return [['payload'], ['fingerprint'], ['person'], ['inactive'], ['evidence'], ['pending'], ['imported']];
    }

    #[DataProvider('corruptions')]
    public function test_corrupt_staging_fails_without_silent_repair(string $case): void
    {
        $batch = $this->prepare();
        $row = $batch->rows()->sole();
        $payload = $row->normalized_payload;
        $raw = $row->raw_provenance;
        $changes = [];
        switch ($case) {
            case 'payload': $payload['record']['predicate_code'] = 'TAMPERED';
                $changes['normalized_payload'] = $payload;
                break;
            case 'fingerprint': $changes['content_fingerprint'] = str_repeat('a', 64);
                break;
            case 'person': $changes['person_id'] = Person::factory()->create()->id;
                break;
            case 'inactive': $raw['source']['source_kind'] = 'PROVISIONAL';
                $changes['raw_provenance'] = $raw;
                break;
            case 'evidence': $payload['stable_identifier_evidence'] = null;
                $changes['normalized_payload'] = $payload;
                break;
            case 'pending': $changes['processing_status'] = 'PENDING';
                break;
            case 'imported':
                $evaluation = app(PerformanceEvaluationService::class)->createInitial($this->actor, $this->record());
                $changes = ['processing_status' => 'IMPORTED', 'performance_evaluation_id' => $evaluation->id];
                break;
        }
        if ($case === 'inactive') {
            // Simulate persisted corruption outside the sanctioned model write path; no production bypass.
            DB::table('pak_skp_import_rows')->where('id', $row->id)->update(['raw_provenance' => json_encode($raw)]);
        } else {
            $row->update($changes);
        }
        $before = $row->fresh()->getAttributes();
        $count = PerformanceEvaluation::count();
        $this->failCode(fn () => $this->service->commit($this->actor, $batch->id), 'FINGERPRINT_PAYLOAD_MISMATCH');
        $this->assertSame('FAILED', $batch->fresh()->status->value);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertDatabaseCount('performance_evaluations', $count);
        $this->assertDatabaseCount('pak_events', 0);
    }

    public static function failurePoints(): array
    {
        return [['first'], ['second'], ['row'], ['closure'], ['audit']];
    }

    #[DataProvider('failurePoints')]
    public function test_each_failure_rolls_back_attempt_and_only_records_batch_failure(string $point): void
    {
        $batch = $this->prepare([[], ['source_record_identifier' => 'REC-002']]);
        $before = $batch->rows()->orderBy('record_ordinal')->get()->map(fn ($row) => $row->getAttributes())->all();
        $enabled = true;
        $calls = 0;
        $callback = function () use (&$enabled, &$calls, $point) {
            if ($enabled && ++$calls >= ($point === 'second' ? 2 : 1)) {
                throw new RuntimeException('PRIVATE SQL SOURCE TRACE');
            }
        };
        match ($point) {
            'first', 'second' => PerformanceEvaluation::created($callback),
            'row' => PakSkpImportRow::updated($callback),
            'closure' => PakSkpImportBatch::updated(function ($model) use ($callback) {
                if ($model->status->value === 'COMMITTED') {
                    $callback();
                }
            }),
            'audit' => PakEvent::created($callback),
        };
        try {
            $this->failCode(fn () => $this->service->commit($this->actor, $batch->id), 'PERSISTENCE_FAILED');
        } finally {
            $enabled = false;
        }
        $this->assertGreaterThan(0, $calls);
        $this->assertSame($before, $batch->rows()->orderBy('record_ordinal')->get()->map(fn ($row) => $row->getAttributes())->all());
        $this->assertSame('FAILED', $batch->fresh()->status->value);
        $this->assertNull($batch->fresh()->committed_at);
        $this->assertSame(['code' => 'PERSISTENCE_FAILED'], $batch->fresh()->error_metadata);
        $this->assertDatabaseCount('performance_evaluations', 0);
        $this->assertDatabaseCount('pak_events', 0);
        Storage::disk('local')->assertExists($batch->object_key);
    }

    private static function queryError(string $state, int $number, string $message): QueryException
    {
        $pdo = new PDOException($message, $state === '40001' ? 40001 : 23000);
        $pdo->errorInfo = [$state, $number, $message];

        return new QueryException('testing', 'synthetic query', [], $pdo);
    }

    public static function retryErrors(): array
    {
        return [
            ['23000', 1062, "Duplicate entry 'x' for key 'pe_current_source_uq'", true],
            ['23000', 1062, "Duplicate entry 'x' for key 'performance_evaluations.pe_current_source_uq'", true],
            ['23000', 19, 'UNIQUE constraint failed: performance_evaluations.current_source_identity_fingerprint', true],
            ['40001', 1213, 'Deadlock found when trying to get lock', true],
            ['23000', 1062, "Duplicate entry 'x' for key 'pe_previous_version_uq'", false],
            ['23000', 1062, "Duplicate entry 'x' for key 'other_pe_current_source_uq'", false],
            ['23000', 1452, 'Foreign key constraint failed', false],
            ['HY000', 1205, 'Lock wait timeout exceeded', false],
            ['HY000', 2006, 'Server has gone away', false],
            ['42000', 1064, 'Syntax error', false],
            ['23000', 19, 'UNIQUE constraint failed: performance_evaluations.previous_version_id', false],
        ];
    }

    #[DataProvider('retryErrors')]
    public function test_retry_detection_is_constraint_and_driver_specific(string $state, int $number, string $message, bool $expected): void
    {
        $error = self::queryError($state, $number, $message);
        $this->assertSame($expected, (new SkpCommitRetryPolicy)->allows($error));
        $this->assertSame($expected, (new SkpCommitRetryPolicy)->allows(new DeadlockException('wrapped', 0, $error)));
    }

    public function test_three_attempts_maximum_and_only_one_file_verification(): void
    {
        $batch = $this->prepare();
        $disk = Storage::disk('local');
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('readStream')->once()->with($batch->object_key)->andReturnUsing(function ($key) use ($disk) {
            $this->assertSame(0, DB::transactionLevel());

            return $disk->readStream($key);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        $attempts = 0;
        $enabled = true;
        PerformanceEvaluation::creating(function () use (&$attempts, &$enabled) {
            if ($enabled) {
                $attempts++;
                throw self::queryError('23000', 1062, "Duplicate entry 'x' for key 'pe_current_source_uq'");
            }
        });
        try {
            $this->failCode(fn () => $this->service->commit($this->actor, $batch->id), 'CONCURRENCY_RETRY_EXHAUSTED');
        } finally {
            $enabled = false;
        }
        $this->assertSame(3, $attempts);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('performance_evaluations', 0);
    }

    public function test_deadlock_restarts_a_fresh_attempt(): void
    {
        $batch = $this->prepare();
        $calls = 0;
        PerformanceEvaluation::creating(function () use (&$calls) {
            if (++$calls === 1) {
                throw self::queryError('40001', 1213, 'Deadlock found when trying to get lock');
            }
        });
        $this->assertSame('COMMITTED', $this->service->commit($this->actor, $batch->id)->status->value);
        $this->assertSame(2, $calls);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('performance_evaluations', 1);
        $this->assertDatabaseCount('pak_events', 1);
    }

    public function test_unrelated_query_failure_is_not_retried(): void
    {
        $batch = $this->prepare();
        $calls = 0;
        PerformanceEvaluation::creating(function () use (&$calls) {
            $calls++;
            throw self::queryError('23000', 1452, 'Foreign key constraint failed');
        });
        $this->failCode(fn () => $this->service->commit($this->actor, $batch->id), 'PERSISTENCE_FAILED');
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('performance_evaluations', 0);
    }

    public static function competingCommitWindows(): array
    {
        return [['before-attempt'], ['after-rollback']];
    }

    #[DataProvider('competingCommitWindows')]
    public function test_persisted_competing_commit_is_returned_and_never_overwritten(string $window): void
    {
        $batch = $this->prepare();
        $runOtherCommit = $window === 'before-attempt';
        $failOnce = $window === 'after-rollback';
        $otherResult = null;
        PerformanceEvaluation::creating(function () use (&$failOnce, &$runOtherCommit) {
            if ($failOnce) {
                $failOnce = false;
                $runOtherCommit = true;
                throw new RuntimeException('Synthetic failure');
            }
        });
        DB::connection()->beforeStartingTransaction(function () use (&$runOtherCommit, &$otherResult, $batch) {
            if ($runOtherCommit && DB::transactionLevel() === 0) {
                $runOtherCommit = false;
                // Simulates a completed competing request at the boundary, not simultaneous locking.
                $otherResult = $this->service->commit($this->actor, $batch->id)->getAttributes();
            }
        });
        $result = $this->service->commit($this->actor, $batch->id);
        $this->assertNotNull($otherResult);
        $this->assertSame($otherResult, $result->getAttributes());
        $this->assertSame('COMMITTED', $result->status->value);
        $this->assertDatabaseCount('performance_evaluations', 1);
        $this->assertDatabaseCount('pak_events', 1);
    }

    public function test_batch_and_current_locks_are_in_transaction_and_identity_order_is_sorted(): void
    {
        $batch = $this->prepare([[], ['source_record_identifier' => 'REC-002']]);
        $locks = [];
        $batchLocks = 0;
        PakSkpImportBatch::addGlobalScope('observe-commit-lock', function ($builder) use (&$batchLocks) {
            $builder->getQuery()->beforeQuery(function ($query) use (&$batchLocks) {
                if ($query->lock === true) {
                    $this->assertGreaterThan(0, DB::transactionLevel());
                    $batchLocks++;
                }
            });
        });
        PerformanceEvaluation::addGlobalScope('observe-identity-lock', function ($builder) use (&$locks) {
            $builder->getQuery()->beforeQuery(function ($query) use (&$locks) {
                if ($query->lock === true) {
                    $this->assertGreaterThan(0, DB::transactionLevel());
                    foreach ($query->wheres as $where) {
                        if (($where['column'] ?? null) === 'current_source_identity_fingerprint') {
                            $locks[] = $where['value'];
                        }
                    }
                }
            });
        });
        $this->service->commit($this->actor, $batch->id);
        $sorted = $locks;
        sort($sorted, SORT_STRING);
        $this->assertCount(2, $locks);
        $this->assertSame($sorted, $locks);
        $this->assertGreaterThan(0, $batchLocks);
    }

    public function test_all_people_are_locked_in_id_order_before_evaluation_identity_processing(): void
    {
        $other = Person::factory()->create(['nip' => '001234567890123457']);
        $batch = $this->prepare([['nip' => $other->nip], ['nip' => $this->person->nip]]);
        $seen = [];
        $evaluationStarted = false;
        Person::addGlobalScope('person-order', function ($builder) use (&$seen, &$evaluationStarted) {
            $builder->getQuery()->beforeQuery(function ($q) use (&$seen, &$evaluationStarted) {
                if ($q->lock === true) {
                    $id = (int) $q->getBindings()[0];
                    if (! in_array($id, $seen, true)) {
                        $this->assertFalse($evaluationStarted, 'No new Person after evaluation locks');
                        $seen[] = $id;
                    }
                }
            });
        });
        PerformanceEvaluation::addGlobalScope('evaluation-order', function ($builder) use (&$seen, &$evaluationStarted, $other) {
            $builder->getQuery()->beforeQuery(function ($q) use (&$seen, &$evaluationStarted, $other) {
                if ($q->lock === true) {
                    $this->assertSame([$this->person->id, $other->id], $seen);
                    $evaluationStarted = true;
                }
            });
        });
        $this->service->commit($this->actor, $batch->id);
        $this->assertTrue($evaluationStarted);
        $this->assertSame([$this->person->id, $other->id], $seen);
        $this->assertSame(2, PerformanceEvaluation::count());
    }

    #[DataProvider('currentContents')]
    public function test_collision_retry_requeries_current_and_reclassifies(string $predicate, string $expected): void
    {
        $batch = $this->prepare();
        $collision = true;
        $insertCompetitor = false;
        $competitor = null;
        PerformanceEvaluation::creating(function () use (&$collision, &$insertCompetitor) {
            if ($collision) {
                $collision = false;
                $insertCompetitor = true;
                throw self::queryError('23000', 1062, "Duplicate entry 'x' for key 'pe_current_source_uq'");
            }
        });
        DB::connection()->beforeStartingTransaction(function () use (&$insertCompetitor, &$competitor, $predicate) {
            if ($insertCompetitor && DB::transactionLevel() === 0) {
                $insertCompetitor = false;
                // Sequential simulation between fully rolled-back attempts, not a concurrency proof.
                $competitor = app(PerformanceEvaluationService::class)->createInitial($this->actor, $this->record(['predicate_code' => $predicate]));
            }
        });
        try {
            $final = $this->service->commit($this->actor, $batch->id);
        } finally {
            $collision = false;
            $insertCompetitor = false;
        }
        $row = $final->rows()->sole();
        $this->assertSame($expected, $row->processing_status->value);
        $this->assertSame($expected === 'DUPLICATE' ? $competitor->id : null, $row->performance_evaluation_id);
        $this->assertDatabaseCount('performance_evaluations', 1);
        $this->assertDatabaseCount('pak_events', 1);
    }

    public static function fileFailures(): array
    {
        return [['missing'], ['changed']];
    }

    #[DataProvider('fileFailures')]
    public function test_file_failure_precedes_domain_transaction_and_preserves_rows(string $failure): void
    {
        $batch = $this->prepare();
        if ($failure === 'missing') {
            Storage::disk('local')->delete($batch->object_key);
        } else {
            Storage::disk('local')->put($batch->object_key, 'different bytes');
        }
        try {
            $this->service->commit($this->actor, $batch->id);
            $this->fail('Expected file failure');
        } catch (SkpSourceStorageException $error) {
            $this->assertSame($failure === 'missing' ? 'SOURCE_STORAGE_READ_FAILED' : 'SOURCE_CHECKSUM_MISMATCH', $error->issue_code);
        }
        $this->assertSame('READY', $batch->fresh()->status->value);
        $this->assertDatabaseCount('performance_evaluations', 0);
        $this->assertDatabaseCount('pak_events', 0);
    }

    public static function unauthorized(): array
    {
        return [['inactive'], ['pak.access'], ['pak.skp.manage']];
    }

    #[DataProvider('unauthorized')]
    public function test_authorization_including_committed_fast_path(string $missing): void
    {
        $batch = $this->prepare();
        $this->service->commit($this->actor, $batch->id);
        if ($missing === 'inactive') {
            $this->actor->forceFill(['is_active' => false])->save();
        } else {
            $this->actor->revokePermissionTo($missing);
        }
        try {
            $this->service->commit($this->actor, $batch->id);
            $this->fail('Expected forbidden');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('pak_events', 1);
        $this->assertDatabaseCount('performance_evaluations', 1);
    }
}
