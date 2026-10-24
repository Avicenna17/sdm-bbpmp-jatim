<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Contracts\SkpSourceAdapter;
use App\Domain\Pak\DTOs\SkpSourceIdentityEvidence;
use App\Domain\Pak\DTOs\SkpSourceRowData;
use App\Domain\Pak\Enums\SkpImportBatchStatus;
use App\Domain\Pak\Enums\SkpImportRowStatus;
use App\Domain\Pak\Enums\SkpPersonResolutionStatus;
use App\Domain\Pak\Exceptions\SkpPreparationException;
use App\Domain\Pak\Exceptions\SkpSourceStorageException;
use App\Domain\Pak\Services\SkpFingerprintSerializer;
use App\Domain\Pak\Services\SkpImportPreparationService;
use App\Domain\Pak\Services\SkpPersonMatcher;
use App\Models\PakSkpImportBatch;
use App\Models\PerformanceEvaluation;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Pak\SkpSourceFixtures;
use Tests\Support\Pak\SyntheticSkpSourceAdapter;
use Tests\TestCase;

class SkpImportPreparationTest extends TestCase
{
    use RefreshDatabase, SkpSourceFixtures;

    private User $actor;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actor = User::factory()->create(['is_active' => true]);
        foreach (['pak.access', 'pak.skp.manage'] as $name) {
            $this->actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->person = Person::factory()->create(['nip' => '001234567890123456']);
    }

    private function source(array $changes = []): array
    {
        return array_replace([
            'nip' => $this->person->nip, 'source_kind' => 'OFFICIAL', 'evaluation_type' => 'ANNUAL',
            'performance_year' => 2025, 'source_reference' => 'SKP/001', 'source_record_identifier' => 'REC-001',
            'predicate_code' => 'BAIK', 'predicate_label_original' => 'Baik',
            'coverage_start' => '2025-01-01', 'coverage_end' => '2025-12-31',
            'source_locator' => ['sheet' => 'SKP', 'row' => 41], 'raw_provenance' => ['label' => ' Baik '],
        ], $changes);
    }

    private function prepare(array $rows, ?SkpSourceAdapter $adapter = null): PakSkpImportBatch
    {
        $stream = fopen('php://memory', 'w+b');
        try {
            fwrite($stream, json_encode($rows, JSON_THROW_ON_ERROR));
            rewind($stream);
            $batch = app(SkpImportPreparationService::class)->prepare($this->actor, $adapter ?? new SyntheticSkpSourceAdapter, $stream, 'source.json', 'local');
            $this->assertTrue(is_resource($stream));

            return $batch;
        } finally {
            fclose($stream);
        }
    }

    private function evaluation(array $changes = [], array $persistedOverrides = []): PerformanceEvaluation
    {
        $raw = $this->source($changes);
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, json_encode([$raw], JSON_THROW_ON_ERROR));
        rewind($stream);
        $source = iterator_to_array((new SyntheticSkpSourceAdapter)->read($stream))[0];
        fclose($stream);
        $serializer = new SkpFingerprintSerializer;
        $record = $serializer->normalize($source, $this->person->id);
        $fields = json_decode($serializer->contentPayload($record), true, flags: JSON_THROW_ON_ERROR);
        unset($fields['schema_version'], $fields['source_schema_version']);

        return PerformanceEvaluation::create(array_replace($fields + ['created_by' => $this->actor->id,
            'source_identity_fingerprint' => $serializer->sourceIdentityFingerprint($record),
            'current_source_identity_fingerprint' => $serializer->sourceIdentityFingerprint($record),
            'content_fingerprint' => $serializer->contentFingerprint($record)], $persistedOverrides));
    }

    public function test_new_candidates_preserve_provenance_and_equivalent_occurrences_remain_ready(): void
    {
        $batch = $this->prepare([$this->source(), $this->source(['source_locator' => ['sheet' => 'COPY', 'row' => 2]])]);
        $this->assertSame(SkpImportBatchStatus::READY, $batch->status);
        $this->assertNull($batch->committed_by);
        $this->assertNull($batch->committed_at);
        $rows = $batch->rows()->orderBy('record_ordinal')->get();
        $this->assertSame([1, 2], $rows->pluck('record_ordinal')->all());
        $this->assertSame(['READY', 'READY'], $rows->map(fn ($row) => $row->processing_status->value)->all());
        $this->assertSame('SKP', $rows[0]->source_sheet);
        $this->assertSame(41, $rows[0]->source_row);
        $this->assertSame($this->source()['source_locator'], $rows[0]->source_locator);
        $this->assertSame($this->source()['raw_provenance'], $rows[0]->raw_provenance['adapter']);
        $this->assertSame($this->source()['source_record_identifier'], $rows[0]->raw_provenance['source']['source_record_identifier']);
        $this->assertSame('EXACT_NIP', $rows[0]->match_method);
        $this->assertSame('REC-001', $rows[0]->normalized_payload['stable_identifier_evidence']['component_value']);
        $this->assertSame('skp-content-v1', $rows[0]->normalized_payload['record']['schema_version']);
        $this->assertSame($rows[0]->source_identity_fingerprint, $rows[1]->source_identity_fingerprint);
        $this->assertSame($rows[0]->content_fingerprint, $rows[1]->content_fingerprint);
        $this->assertNull($rows[1]->performance_evaluation_id);
        $this->assertSame(['representative_ordinal' => 1, 'occurrence_ordinals' => [1, 2]], $rows[1]->normalized_payload['preparation_group']);
        $this->assertSame($rows[1]->normalized_payload['preparation_group'], $rows[0]->normalized_payload['preparation_group']);
        $this->assertSame(2, $batch->summary['outcomes']['READY']);
        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseCount('performance_evaluations', 0);
        $this->assertDatabaseCount('pak_events', 0);
        Storage::disk('local')->assertExists($batch->object_key);
    }

    public static function invalidSources(): array
    {
        return [
            [['source_kind' => 'PROVISIONAL'], 'SOURCE_KIND_NOT_ACTIVE', 'PENDING', 'INVALID'],
            [['evaluation_type' => 'CUSTOM_PERIOD'], 'EVALUATION_TYPE_NOT_ACTIVE', 'PENDING', 'INVALID'],
            [['source_kind' => 'UNKNOWN'], 'SOURCE_KIND_INVALID', 'PENDING', 'INVALID'],
            [['evaluation_type' => null], 'EVALUATION_TYPE_INVALID', 'PENDING', 'INVALID'],
            [['nip' => '111111111111111111'], 'PERSON_NOT_FOUND', 'NOT_FOUND', 'INVALID'],
            [['nip' => null], 'PERSON_IDENTIFIER_INVALID', 'INVALID', 'INVALID'],
            [['nip' => '00 1234567890123456'], 'PERSON_IDENTIFIER_INVALID', 'INVALID', 'INVALID'],
            [['source_record_identifier' => null], 'SOURCE_IDENTITY_AMBIGUOUS', 'MATCHED', 'IDENTITY_AMBIGUOUS'],
            [['source_record_identifier' => '  '], 'SOURCE_IDENTITY_AMBIGUOUS', 'MATCHED', 'IDENTITY_AMBIGUOUS'],
            [['coverage_end' => '2026-01-01'], 'SOURCE_VALIDATION_FAILED', 'MATCHED', 'INVALID'],
            [['validation_issues' => ['DO_NOT_LOG_PRIVATE_SOURCE']], 'SOURCE_VALIDATION_FAILED', 'PENDING', 'INVALID'],
        ];
    }

    #[DataProvider('invalidSources')]
    public function test_known_issues_are_retained_in_ready_mixed_batch(array $changes, string $issue, string $resolution, string $status): void
    {
        $batch = $this->prepare([$this->source($changes), $this->source()]);
        $row = $batch->rows()->orderBy('record_ordinal')->first();
        $this->assertSame(SkpImportBatchStatus::READY, $batch->status);
        $this->assertSame($status, $row->processing_status->value);
        $this->assertSame($resolution, $row->person_resolution_status->value);
        $this->assertSame([$issue], $row->issue_codes);
        $this->assertNull($row->source_identity_fingerprint);
        $this->assertNull($row->content_fingerprint);
        $this->assertNull($row->normalized_payload);
        if ($resolution !== 'MATCHED') {
            $this->assertNull($row->person_id);
        }
        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseCount('performance_evaluations', 0);
    }

    public function test_person_ambiguity_is_invalid_not_source_identity_ambiguity(): void
    {
        $this->app->instance(SkpPersonMatcher::class, new class extends SkpPersonMatcher
        {
            protected function candidateIds(string $nip): array
            {
                return [1, 2];
            }
        });
        $row = $this->prepare([$this->source()])->rows()->first();
        $this->assertSame(SkpImportRowStatus::INVALID, $row->processing_status);
        $this->assertSame(SkpPersonResolutionStatus::AMBIGUOUS, $row->person_resolution_status);
        $this->assertSame(['PERSON_AMBIGUOUS'], $row->issue_codes);
        $this->assertNull($row->person_id);
        $this->assertNull($row->content_fingerprint);
    }

    public function test_current_duplicate_and_conflict_are_read_only_and_conflict_has_no_link(): void
    {
        $evaluation = $this->evaluation();
        $before = $evaluation->fresh()->getAttributes();
        $batch = $this->prepare([$this->source(), $this->source()]);
        foreach ($batch->rows as $row) {
            $this->assertSame(SkpImportRowStatus::DUPLICATE, $row->processing_status);
            $this->assertSame($evaluation->id, $row->performance_evaluation_id);
            $this->assertArrayNotHasKey('preparation_group', $row->normalized_payload);
        }
        $row = $this->prepare([$this->source(['predicate_code' => 'CUKUP'])])->rows()->first();
        $this->assertSame(SkpImportRowStatus::CONFLICT, $row->processing_status);
        $this->assertNull($row->performance_evaluation_id);
        $this->assertSame(['SOURCE_CONTENT_CONFLICT'], $row->issue_codes);
        $this->assertArrayNotHasKey('preparation_group', $row->normalized_payload);
        $this->assertSame($before, $evaluation->fresh()->getAttributes());
        $this->assertDatabaseCount('performance_evaluations', 1);
        $this->assertDatabaseCount('pak_events', 0);
    }

    public function test_intra_batch_conflicting_content_has_no_ordinal_winner_even_with_current(): void
    {
        $this->evaluation();
        $batch = $this->prepare([$this->source(), $this->source(['predicate_code' => 'CUKUP'])]);
        foreach ($batch->rows as $row) {
            $this->assertSame(SkpImportRowStatus::CONFLICT, $row->processing_status);
            $this->assertNull($row->performance_evaluation_id);
            $this->assertArrayNotHasKey('preparation_group', $row->normalized_payload);
        }
        $this->assertDatabaseCount('performance_evaluations', 1);
    }

    public function test_new_intra_batch_conflict_has_no_representative(): void
    {
        $batch = $this->prepare([$this->source(), $this->source(['predicate_code' => 'CUKUP'])]);
        foreach ($batch->rows as $row) {
            $this->assertSame(SkpImportRowStatus::CONFLICT, $row->processing_status);
            $this->assertNull($row->performance_evaluation_id);
            $this->assertArrayNotHasKey('preparation_group', $row->normalized_payload);
        }
        $this->assertDatabaseCount('performance_evaluations', 0);
    }

    public function test_superseded_version_is_never_classification_target(): void
    {
        $old = $this->evaluation();
        $this->supersede($old);
        $current = $this->evaluation(['predicate_code' => 'CUKUP']);
        $row = $this->prepare([$this->source()])->rows()->first();
        $this->assertSame(SkpImportRowStatus::CONFLICT, $row->processing_status);
        $this->assertNull($row->performance_evaluation_id);
        $this->assertSame('CURRENT', $current->fresh()->status->value);
        $this->assertDatabaseCount('performance_evaluations', 2);
    }

    public function test_inconsistent_persisted_fingerprint_is_integrity_failure_not_duplicate(): void
    {
        $evaluation = $this->evaluation([], ['content_fingerprint' => str_repeat('a', 64)]);
        $before = $evaluation->fresh()->getAttributes();
        try {
            $this->prepare([$this->source()]);
            $this->fail('Expected integrity failure');
        } catch (SkpPreparationException $error) {
            $this->assertSame('FINGERPRINT_PAYLOAD_MISMATCH', $error->issue_code);
            $batch = PakSkpImportBatch::findOrFail($error->batch_id);
            $this->assertSame(SkpImportBatchStatus::FAILED, $batch->status);
            $this->assertSame(SkpImportRowStatus::FAILED, $batch->rows()->first()->processing_status);
        }
        $this->assertSame($before, $evaluation->fresh()->getAttributes());
    }

    public function test_parsing_batch_with_existing_occurrences_cannot_be_restarted(): void
    {
        $batch = PakSkpImportBatch::create($this->batchData());
        $row = $this->pendingRow($batch);
        $before = $row->fresh()->getAttributes();
        try {
            app(SkpImportPreparationService::class)->prepareBatch($this->actor, $batch->id, new SyntheticSkpSourceAdapter);
            $this->fail('Expected single-use rejection');
        } catch (SkpPreparationException $error) {
            $this->assertSame('PREPARATION_NOT_ALLOWED', $error->issue_code);
        }
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(SkpImportBatchStatus::PARSING, $batch->fresh()->status);
    }

    private function failingAdapter(bool $structural): SkpSourceAdapter
    {
        return new class($structural) implements SkpSourceAdapter
        {
            public function __construct(private bool $structural) {}

            public function key(): string
            {
                return 'synthetic-skp';
            }

            public function version(): string
            {
                return '1';
            }

            public function schemaVersion(): string
            {
                return 'synthetic-skp-source-v1';
            }

            public function read(mixed $stream): iterable
            {
                yield from (new SyntheticSkpSourceAdapter)->read($stream);
                if ($this->structural) {
                    throw ValidationException::withMessages(['file' => 'SECRET PRIVATE CONTENT']);
                }
                throw new RuntimeException('SECRET PRIVATE CONTENT');
            }
        };
    }

    public function test_structural_failure_is_invalid_and_retains_file_and_occurrences(): void
    {
        $batch = $this->prepare([$this->source()], $this->failingAdapter(true));
        $this->assertSame(SkpImportBatchStatus::INVALID, $batch->status);
        $this->assertSame(['code' => 'SOURCE_VALIDATION_FAILED'], $batch->error_metadata);
        $this->assertSame(SkpImportRowStatus::INVALID, $batch->rows()->first()->processing_status);
        Storage::disk('local')->assertExists($batch->object_key);
    }

    public function test_technical_failure_is_failed_and_not_silently_swallowed(): void
    {
        try {
            $this->prepare([$this->source()], $this->failingAdapter(false));
            $this->fail('Expected technical failure');
        } catch (SkpPreparationException $error) {
            $this->assertSame('SOURCE_ADAPTER_FAILED', $error->issue_code);
            $batch = PakSkpImportBatch::findOrFail($error->batch_id);
            $this->assertSame(SkpImportBatchStatus::FAILED, $batch->status);
            $this->assertSame(['code' => 'SOURCE_ADAPTER_FAILED'], $batch->error_metadata);
            $this->assertSame(SkpImportRowStatus::FAILED, $batch->rows()->first()->processing_status);
            $this->assertStringNotContainsString('SECRET', json_encode($batch->error_metadata));
            Storage::disk('local')->assertExists($batch->object_key);
        }
        $this->assertDatabaseCount('performance_evaluations', 0);
    }

    public static function closedStates(): array
    {
        return [['READY'], ['INVALID'], ['FAILED'], ['COMMITTED']];
    }

    #[DataProvider('closedStates')]
    public function test_only_empty_parsing_batch_can_be_prepared(string $status): void
    {
        $batch = PakSkpImportBatch::create($this->batchData(['status' => $status,
            'committed_by' => $status === 'COMMITTED' ? $this->actor->id : null,
            'committed_at' => $status === 'COMMITTED' ? now() : null]));
        try {
            app(SkpImportPreparationService::class)->prepareBatch($this->actor, $batch->id, new SyntheticSkpSourceAdapter);
            $this->fail('Expected state rejection');
        } catch (SkpPreparationException $error) {
            $this->assertSame('PREPARATION_NOT_ALLOWED', $error->issue_code);
        }
        $this->assertDatabaseCount('pak_skp_import_rows', 0);
        $this->assertSame($status, $batch->fresh()->status->value);
    }

    public function test_reprepare_does_not_mutate_existing_rows(): void
    {
        $batch = $this->prepare([$this->source()]);
        $before = $batch->rows()->first()->getAttributes();
        try {
            app(SkpImportPreparationService::class)->prepareBatch($this->actor, $batch->id, new SyntheticSkpSourceAdapter);
            $this->fail('Expected single-use rejection');
        } catch (SkpPreparationException $error) {
            $this->assertSame('PREPARATION_NOT_ALLOWED', $error->issue_code);
        }
        $this->assertSame($before, $batch->rows()->first()->getAttributes());
    }

    public function test_row_writes_and_finalization_recheck_locked_batch_in_transaction(): void
    {
        $locks = 0;
        $level = DB::transactionLevel();
        $scopes = PakSkpImportBatch::getAllGlobalScopes();
        PakSkpImportBatch::addGlobalScope('observe-preparation-lock', function ($builder) use (&$locks, $level) {
            $builder->getQuery()->beforeQuery(function ($query) use (&$locks, $level) {
                if ($query->lock === true) {
                    $this->assertGreaterThan($level, DB::transactionLevel());
                    $locks++;
                }
            });
        });
        try {
            $batch = $this->prepare([$this->source()]);
        } finally {
            PakSkpImportBatch::setAllGlobalScopes($scopes);
        }
        // Initial check, occurrence create, outcome update, and finalization; no SQL-string assertions.
        $this->assertSame(4, $locks);
        $this->assertSame(SkpImportBatchStatus::READY, $batch->status);
    }

    public function test_batch_creation_failure_compensates_new_file(): void
    {
        $enabled = true;
        DB::connection()->beforeExecuting(function ($sql) use (&$enabled) {
            if ($enabled && str_starts_with($sql, 'insert into "pak_skp_import_batches"')) {
                throw new RuntimeException('private database diagnostic');
            }
        });
        try {
            $this->prepare([$this->source()]);
            $this->fail('Expected persistence failure');
        } catch (SkpPreparationException $error) {
            $this->assertSame('PERSISTENCE_FAILED', $error->issue_code);
            $this->assertNull($error->batch_id);
        } finally {
            $enabled = false;
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('pak_skp_import_batches', 0);
    }

    public function test_authorization_rejected_before_storage(): void
    {
        $this->actor->forceFill(['is_active' => false])->save();
        try {
            $this->prepare([$this->source()]);
            $this->fail('Expected forbidden');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('pak_skp_import_batches', 0);
    }

    /** Adapter test hook only; production adapters remain independent of persistence. */
    private function mappedAdapter(callable $map, ?callable $after = null): SkpSourceAdapter
    {
        return new class($map, $after) implements SkpSourceAdapter
        {
            public function __construct(private mixed $map, private mixed $after) {}

            public function key(): string
            {
                return 'synthetic-skp';
            }

            public function version(): string
            {
                return '1';
            }

            public function schemaVersion(): string
            {
                return 'synthetic-skp-source-v1';
            }

            public function read(mixed $stream): iterable
            {
                foreach ((new SyntheticSkpSourceAdapter)->read($stream) as $ordinal => $row) {
                    yield ($this->map)($row, $ordinal);
                }
                if ($this->after !== null) {
                    ($this->after)();
                }
            }
        };
    }

    public function test_missing_or_inconsistent_structural_proof_is_identity_ambiguous(): void
    {
        $adapter = $this->mappedAdapter(fn ($row) => new SkpSourceRowData(...array_replace(get_object_vars($row), ['stable_identifier_evidence' => null])));
        $row = $this->prepare([$this->source()], $adapter)->rows()->first();
        $this->assertSame(SkpImportRowStatus::IDENTITY_AMBIGUOUS, $row->processing_status);
        $this->assertNull($row->source_identity_fingerprint);
        $adapter = $this->mappedAdapter(fn ($row, $ordinal) => new SkpSourceRowData(...array_replace(get_object_vars($row), [
            'stable_identifier_evidence' => new SkpSourceIdentityEvidence('synthetic-record-code-v1', $ordinal === 0 ? 'register-a' : 'register-b', 'REC-001'),
        ])));
        $batch = $this->prepare([$this->source(), $this->source()], $adapter);
        foreach ($batch->rows as $row) {
            $this->assertSame(SkpImportRowStatus::IDENTITY_AMBIGUOUS, $row->processing_status);
            $this->assertNull($row->source_identity_fingerprint);
            $this->assertNull($row->content_fingerprint);
            $this->assertNull($row->performance_evaluation_id);
        }
    }

    public function test_status_changed_during_parsing_is_rechecked_before_row_creation(): void
    {
        $level = DB::transactionLevel();
        $adapter = $this->mappedAdapter(function ($row) use ($level) {
            $this->assertSame($level, DB::transactionLevel(), 'Parsing must not hold the preparation transaction.');
            PakSkpImportBatch::firstOrFail()->update(['status' => SkpImportBatchStatus::READY]);

            return $row;
        });
        try {
            $this->prepare([$this->source()], $adapter);
            $this->fail('Expected persisted state rejection');
        } catch (SkpPreparationException $error) {
            $this->assertSame('PREPARATION_NOT_ALLOWED', $error->issue_code);
        }
        $this->assertDatabaseCount('pak_skp_import_rows', 0);
        $this->assertSame(SkpImportBatchStatus::READY, PakSkpImportBatch::first()->status);
    }

    public function test_status_changed_before_finalization_is_not_overwritten(): void
    {
        $adapter = $this->mappedAdapter(fn ($row) => $row, fn () => PakSkpImportBatch::firstOrFail()->update(['status' => SkpImportBatchStatus::INVALID]));
        try {
            $this->prepare([], $adapter);
            $this->fail('Expected finalization state rejection');
        } catch (SkpPreparationException $error) {
            $this->assertSame('PREPARATION_NOT_ALLOWED', $error->issue_code);
        }
        $this->assertSame(SkpImportBatchStatus::INVALID, PakSkpImportBatch::first()->status);
    }

    public function test_row_persistence_failure_is_failed_with_retained_evidence(): void
    {
        $enabled = true;
        DB::connection()->beforeExecuting(function ($sql) use (&$enabled) {
            if ($enabled && str_starts_with($sql, 'insert into "pak_skp_import_rows"')) {
                $enabled = false;
                throw new RuntimeException('SECRET SQL');
            }
        });
        try {
            $this->prepare([$this->source()]);
            $this->fail('Expected persistence failure');
        } catch (SkpPreparationException $error) {
            $this->assertSame('PERSISTENCE_FAILED', $error->issue_code);
            $batch = PakSkpImportBatch::findOrFail($error->batch_id);
            $this->assertSame(SkpImportBatchStatus::FAILED, $batch->status);
            Storage::disk('local')->assertExists($batch->object_key);
            $this->assertSame(['code' => 'PERSISTENCE_FAILED'], $batch->error_metadata);
        } finally {
            $enabled = false;
        }
        $this->assertDatabaseCount('pak_skp_import_rows', 0);
        $this->assertDatabaseCount('performance_evaluations', 0);
    }

    public function test_compensation_failure_propagates_storage_recovery_metadata(): void
    {
        $disk = Storage::disk('local');
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        $enabled = true;
        DB::connection()->beforeExecuting(function ($sql) use (&$enabled) {
            if ($enabled && str_starts_with($sql, 'insert into "pak_skp_import_batches"')) {
                throw new RuntimeException('database failure');
            }
        });
        try {
            $this->prepare([$this->source()]);
            $this->fail('Expected recovery metadata');
        } catch (SkpSourceStorageException $error) {
            $this->assertSame('SOURCE_CLEANUP_FAILED', $error->issue_code);
            $this->assertNotNull($error->recovery_source);
            $this->assertTrue($disk->exists($error->recovery_source->object_key));
        } finally {
            $enabled = false;
        }
        $this->assertDatabaseCount('pak_skp_import_batches', 0);
    }

    public static function missingPermissions(): array
    {
        return [['pak.access'], ['pak.skp.manage']];
    }

    #[DataProvider('missingPermissions')]
    public function test_each_required_permission_is_enforced_before_storage(string $permission): void
    {
        $this->actor->revokePermissionTo($permission);
        try {
            $this->prepare([$this->source()]);
            $this->fail('Expected forbidden');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('pak_skp_import_batches', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
