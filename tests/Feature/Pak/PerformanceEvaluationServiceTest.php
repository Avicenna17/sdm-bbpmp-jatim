<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\NormalizedSkpRecord;
use App\Domain\Pak\DTOs\SkpSourceIdentityEvidence;
use App\Domain\Pak\DTOs\SkpSourceRowData;
use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;
use App\Domain\Pak\Services\PerformanceEvaluationService;
use App\Domain\Pak\Services\SkpFingerprintSerializer;
use App\Domain\Pak\Services\SkpImportPreparationService;
use App\Models\EmployeePositionHistory;
use App\Models\PakEvent;
use App\Models\PakSkpImportBatch;
use App\Models\PakSkpImportRow;
use App\Models\PerformanceEvaluation;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Pak\SkpSourceFixtures;
use Tests\Support\Pak\SyntheticSkpSourceAdapter;
use Tests\TestCase;

class PerformanceEvaluationServiceTest extends TestCase
{
    use SkpSourceFixtures;

    private User $actor;

    private Person $person;

    private PerformanceEvaluationService $service;

    private SkpFingerprintSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Service tests require isolated in-memory SQLite.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
        $this->actor = $this->authorizedActor();
        $this->person = Person::factory()->create(['nip' => '001234567890123456']);
        $this->service = app(PerformanceEvaluationService::class);
        $this->serializer = app(SkpFingerprintSerializer::class);
    }

    private function authorizedActor(): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        foreach (['pak.access', 'pak.skp.manage'] as $name) {
            $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        return $actor;
    }

    private function record(array $changes = [], ?int $personId = null): NormalizedSkpRecord
    {
        $values = array_replace([
            'source_kind' => PerformanceSourceKind::OFFICIAL, 'evaluation_type' => PerformanceEvaluationType::ANNUAL,
            'performance_year' => 2025, 'source_reference' => ' SKP/001 ', 'source_record_identifier' => 'REC-001',
            'predicate_code' => 'BAIK', 'predicate_label_original' => ' Baik  Sekali ',
            'coverage_start' => '2025-01-01', 'coverage_end' => '2025-12-31',
            'source_date' => '2026-01-01', 'source_position_name' => ' Jabatan  sintetis ',
            'source_functional_level_label' => 'Label sumber', 'source_position_tmt' => '2024-06-01',
            'source_sk_number' => ' 001/SK ', 'source_sk_date' => '2024-05-01',
        ], $changes);
        $values['stable_identifier_evidence'] = new SkpSourceIdentityEvidence('synthetic-code-v1', 'record_code', $values['source_record_identifier']);

        return $this->serializer->normalize(new SkpSourceRowData(...$values), $personId ?? $this->person->id);
    }

    private function evidence(NormalizedSkpRecord $record, string $status = 'CONFLICT'): PakSkpImportRow
    {
        $batch = PakSkpImportBatch::create($this->batchData(['created_by' => $this->actor->id]));

        return PakSkpImportRow::create([
            'batch_id' => $batch->id, 'record_ordinal' => 1, 'processing_status' => $status,
            'person_resolution_status' => 'MATCHED', 'person_id' => $record->person_id, 'match_method' => 'EXACT_NIP',
            'source_reference' => $record->source_reference, 'source_record_identifier' => $record->source_record_identifier,
            'source_identity_fingerprint' => $this->serializer->sourceIdentityFingerprint($record),
            'content_fingerprint' => $this->serializer->contentFingerprint($record),
            'normalized_payload' => ['record' => json_decode($this->serializer->contentPayload($record), true, flags: JSON_THROW_ON_ERROR),
                'stable_identifier_evidence' => get_object_vars($record->stable_identifier_evidence)],
            'raw_provenance' => ['private' => 'PRIVATE_SENTINEL'],
        ]);
    }

    private function rejected(callable $action, string $code): void
    {
        try {
            $action();
            $this->fail('Expected domain rejection: '.$code);
        } catch (ValidationException $error) {
            $this->assertContains($code, collect($error->errors())->flatten()->all());
        }
    }

    private function unchanged(PerformanceEvaluation $previous, array $before): void
    {
        $this->assertSame($before, $previous->fresh()->getAttributes());
        $this->assertDatabaseCount('performance_evaluations', 1);
        $this->assertDatabaseCount('pak_events', 0);
    }

    public function test_initial_create_persists_canonical_fields_and_server_hashes_without_import_or_audit_mutation(): void
    {
        $record = $this->record();
        $row = $this->evidence($record, 'READY');
        $rowBefore = $row->fresh()->getAttributes();
        $batchBefore = $row->batch->getAttributes();
        $evaluation = $this->service->createInitial($this->actor, $record)->fresh();
        $expected = json_decode($this->serializer->contentPayload($record), true, flags: JSON_THROW_ON_ERROR);
        unset($expected['schema_version'], $expected['source_schema_version']);
        foreach ($expected as $field => $value) {
            $actual = $evaluation->$field instanceof \DateTimeInterface ? $evaluation->$field->format('Y-m-d') : $evaluation->getRawOriginal($field);
            $this->assertSame($value, $actual);
        }
        $this->assertSame('CURRENT', $evaluation->status->value);
        $this->assertSame($this->serializer->sourceIdentityFingerprint($record), $evaluation->source_identity_fingerprint);
        $this->assertSame($this->serializer->contentFingerprint($record), $evaluation->content_fingerprint);
        $this->assertSame($evaluation->source_identity_fingerprint, $evaluation->current_source_identity_fingerprint);
        $this->assertSame($this->actor->id, $evaluation->created_by);
        foreach (['previous_version_id', 'revision_reason', 'superseded_by_user_id', 'superseded_at', 'historical_reason', 'employee_position_history_id'] as $field) {
            $this->assertNull($evaluation->$field);
        }
        $this->assertSame($rowBefore, $row->fresh()->getAttributes());
        $this->assertSame($batchBefore, $row->batch()->first()->getAttributes());
        $this->assertDatabaseCount('performance_evaluations', 1);
        $this->assertDatabaseCount('pak_events', 0);
    }

    public static function activeInitialTypes(): array
    {
        return [[PerformanceEvaluationType::Q1], [PerformanceEvaluationType::Q2], [PerformanceEvaluationType::Q3],
            [PerformanceEvaluationType::Q4], [PerformanceEvaluationType::ANNUAL]];
    }

    #[DataProvider('activeInitialTypes')]
    public function test_official_active_types_can_be_created(PerformanceEvaluationType $type): void
    {
        $record = $this->record(['evaluation_type' => $type]);
        $evaluation = $this->service->createInitial($this->actor, $record);
        $this->assertSame(PerformanceSourceKind::OFFICIAL, $evaluation->source_kind);
        $this->assertSame($type, $evaluation->evaluation_type);
        $this->assertSame('CURRENT', $evaluation->status->value);
        $this->assertDatabaseCount('performance_evaluations', 1);
        $this->assertDatabaseCount('pak_events', 0);
    }

    public static function historicalInitialSources(): array
    {
        return [
            [['source_kind' => PerformanceSourceKind::PROVISIONAL], 'SOURCE_KIND_NOT_ACTIVE'],
            [['evaluation_type' => PerformanceEvaluationType::CUSTOM_PERIOD], 'EVALUATION_TYPE_NOT_ACTIVE'],
        ];
    }

    #[DataProvider('historicalInitialSources')]
    public function test_historical_records_remain_serializable_but_cannot_create_initial(array $changes, string $code): void
    {
        $record = $this->record($changes);
        $payload = json_decode($this->serializer->contentPayload($record), true, flags: JSON_THROW_ON_ERROR);
        foreach ($changes as $field => $value) {
            $this->assertSame($value->value, $payload[$field]);
        }
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $this->serializer->sourceIdentityFingerprint($record));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $this->serializer->contentFingerprint($record));
        $this->rejected(fn () => $this->service->createInitial($this->actor, $record), $code);
        $this->assertDatabaseCount('performance_evaluations', 0);
        $this->assertDatabaseCount('pak_events', 0);
    }

    public function test_initial_creation_leaves_unique_collision_visible_without_returning_existing_evaluation(): void
    {
        $record = $this->record();
        $first = $this->service->createInitial($this->actor, $record);
        try {
            $this->service->createInitial($this->actor, $record);
            $this->fail('Expected database unique collision');
        } catch (QueryException) {
            $this->assertSame('CURRENT', $first->fresh()->status->value);
        }
        $this->assertDatabaseCount('performance_evaluations', 1);
        $this->assertDatabaseCount('pak_events', 0);
    }

    public static function forgedRecords(): array
    {
        return [[['source_reference' => ' SKP/001 ']], [['source_record_identifier' => '']],
            [['predicate_label_original' => 'Baik  Sekali']], [['coverage_end' => '2026-01-01']]];
    }

    #[DataProvider('forgedRecords')]
    public function test_initial_rejects_noncanonical_or_ineligible_internal_factory_records(array $changes): void
    {
        $record = NormalizedSkpRecord::fromCanonicalValues(array_replace(get_object_vars($this->record()), $changes));
        try {
            $this->service->createInitial($this->actor, $record);
            $this->fail('Expected serializer rejection');
        } catch (ValidationException) {
            $this->assertDatabaseCount('performance_evaluations', 0);
        }
    }

    public function test_initial_create_is_composable_with_outer_transaction_rollback(): void
    {
        try {
            DB::transaction(function () {
                $this->service->createInitial($this->actor, $this->record());
                $this->assertDatabaseCount('performance_evaluations', 1);
                throw new RuntimeException('synthetic outer rollback');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('synthetic outer rollback', $error->getMessage());
        }
        $this->assertDatabaseCount('performance_evaluations', 0);
    }

    public function test_successful_correction_preserves_identity_history_and_safe_atomic_audit(): void
    {
        $initial = $this->record();
        $old = $this->service->createInitial($this->actor, $initial);
        $proposed = $this->record(['predicate_code' => 'CUKUP']);
        $row = $this->evidence($proposed);
        // Test fixture only: a committed conflict remains valid, read-only evidence.
        $row->batch->update(['status' => 'COMMITTED', 'committed_by' => $this->actor->id, 'committed_at' => now()]);
        $rowBefore = $row->fresh()->getAttributes();
        $batchBefore = $row->batch()->first()->getAttributes();
        $corrector = $this->authorizedActor();
        $next = $this->service->correct($corrector, $old->id, $proposed, 'Koreksi sumber resmi', $row->id)->fresh();
        $old->refresh();
        $this->assertSame('SUPERSEDED', $old->status->value);
        $this->assertNull($old->current_source_identity_fingerprint);
        $this->assertSame($corrector->id, $old->superseded_by_user_id);
        $this->assertNotNull($old->superseded_at);
        $this->assertSame($this->serializer->contentFingerprint($initial), $old->content_fingerprint);
        $this->assertSame('BAIK', $old->predicate_code);
        $this->assertSame('CURRENT', $next->status->value);
        $this->assertSame($old->id, $next->previous_version_id);
        $this->assertSame('Koreksi sumber resmi', $next->revision_reason);
        $this->assertSame($corrector->id, $next->created_by);
        $this->assertNull($next->superseded_by_user_id);
        $this->assertNull($next->superseded_at);
        $this->assertNull($next->historical_reason);
        foreach (['person_id', 'source_kind', 'performance_year', 'evaluation_type', 'source_reference', 'source_record_identifier', 'source_identity_fingerprint'] as $field) {
            $this->assertSame($old->$field, $next->$field);
        }
        $this->assertSame($next->source_identity_fingerprint, $next->current_source_identity_fingerprint);
        $this->assertSame($this->serializer->contentFingerprint($proposed), $next->content_fingerprint);
        $this->assertNotSame($old->content_fingerprint, $next->content_fingerprint);
        $this->assertSame(1, $old->successor()->count());
        $event = PakEvent::sole();
        $this->assertSame('PERFORMANCE_EVALUATION_CORRECTED', $event->event_code);
        $this->assertSame('PerformanceEvaluation', $event->subject_type);
        $this->assertSame($next->id, $event->subject_id);
        $this->assertSame($corrector->id, $event->actor_id);
        $this->assertNotNull($event->occurred_at);
        $this->assertSame(['before' => ['id' => $old->id, 'status' => 'CURRENT'],
            'after' => ['id' => $next->id, 'status' => 'CURRENT', 'source_row_id' => $row->id],
            'reason' => 'Koreksi sumber resmi', 'supersedes_id' => $old->id], $event->payload);
        $this->assertStringNotContainsString('PRIVATE_SENTINEL', json_encode($event->payload));
        $this->assertSame($rowBefore, $row->fresh()->getAttributes());
        $this->assertSame($batchBefore, $row->batch()->first()->getAttributes());
    }

    public static function noChanges(): array
    {
        return [[[]], [['predicate_label_original' => " Baik\tSekali\n", 'source_reference' => ' SKP/001 ']]];
    }

    #[DataProvider('noChanges')]
    public function test_same_or_cosmetic_content_and_reason_only_reject_before_mutation(array $changes): void
    {
        $old = $this->service->createInitial($this->actor, $this->record())->fresh();
        $before = $old->getAttributes();
        // Missing provenance ID intentionally proves NO_CHANGES is checked before provenance/mutations.
        $this->rejected(fn () => $this->service->correct($this->actor, $old->id, $this->record($changes), 'Reason changed only', 9999), 'NO_CHANGES');
        $this->unchanged($old, $before);
    }

    public static function identityChanges(): array
    {
        return [['person_id'], ['source_kind'], ['performance_year'], ['evaluation_type'], ['source_reference'], ['source_record_identifier']];
    }

    #[DataProvider('identityChanges')]
    public function test_each_identity_change_is_rejected_without_mutation(string $field): void
    {
        $old = $this->service->createInitial($this->actor, $this->record())->fresh();
        $before = $old->getAttributes();
        $changes = match ($field) {
            'source_kind' => ['source_kind' => PerformanceSourceKind::PROVISIONAL],
            'performance_year' => ['performance_year' => 2024, 'coverage_start' => '2024-01-01', 'coverage_end' => '2024-12-31'],
            'evaluation_type' => ['evaluation_type' => PerformanceEvaluationType::Q4],
            'source_reference' => ['source_reference' => 'SKP/OTHER'],
            'source_record_identifier' => ['source_record_identifier' => 'REC-002'],
            default => [],
        };
        $record = $this->record($changes, $field === 'person_id' ? Person::factory()->create()->id : null);
        $this->rejected(fn () => $this->service->correct($this->actor, $old->id, $record, 'Correction', 9999), 'SOURCE_IDENTITY_CHANGE_NOT_ALLOWED');
        $this->unchanged($old, $before);
    }

    public function test_stale_predecessor_is_not_followed_to_successor(): void
    {
        $old = $this->service->createInitial($this->actor, $this->record());
        $proposed = $this->record(['predicate_code' => 'CUKUP']);
        $row = $this->evidence($proposed);
        $next = $this->service->correct($this->actor, $old->id, $proposed, 'Correction', $row->id);
        $this->rejected(fn () => $this->service->correct($this->actor, $old->id, $proposed, 'Stale correction', $row->id), 'PREDECESSOR_NOT_CURRENT');
        $this->assertSame('CURRENT', $next->fresh()->status->value);
        $this->assertDatabaseCount('performance_evaluations', 2);
        $this->assertDatabaseCount('pak_events', 1);
    }

    public static function invalidProvenance(): array
    {
        return [['missing_payload'], ['missing_fingerprint'], ['content_hash'], ['source_hash'], ['wrong_person'],
            ['unresolved_person'], ['payload_content'], ['payload_identity'], ['noncanonical_payload'], ['payload_version'],
            ['missing_nullable_key'], ['extra_key'], ['missing_evidence'], ['invalid_evidence'], ['wrong_reference'],
            ['wrong_identifier'], ['different_valid_content']];
    }

    #[DataProvider('invalidProvenance')]
    public function test_provenance_must_be_canonical_consistent_and_support_proposed_content(string $case): void
    {
        $old = $this->service->createInitial($this->actor, $this->record())->fresh();
        $before = $old->getAttributes();
        $proposed = $this->record(['predicate_code' => 'CUKUP']);
        $row = $this->evidence($case === 'different_valid_content' ? $this->record(['predicate_code' => 'OTHER']) : $proposed, 'PENDING');
        $payload = $row->normalized_payload;
        $changes = match ($case) {
            'missing_payload' => ['normalized_payload' => null],
            'missing_fingerprint' => ['content_fingerprint' => null],
            'content_hash' => ['content_fingerprint' => str_repeat('a', 64)],
            'source_hash' => ['source_identity_fingerprint' => str_repeat('b', 64)],
            'wrong_person' => ['person_id' => Person::factory()->create()->id],
            'unresolved_person' => ['person_resolution_status' => 'NOT_FOUND', 'person_id' => null],
            'wrong_reference' => ['source_reference' => 'OTHER'],
            'wrong_identifier' => ['source_record_identifier' => 'OTHER'],
            default => [],
        };
        switch ($case) {
            case 'payload_content': $payload['record']['predicate_code'] = 'OTHER';
                break;
            case 'payload_identity': $payload['record']['source_reference'] = 'OTHER';
                break;
            case 'noncanonical_payload': $payload['record']['predicate_label_original'] = ' Baik  Sekali ';
                break;
            case 'payload_version': $payload['record']['schema_version'] = 'UNKNOWN';
                break;
            case 'missing_nullable_key': unset($payload['record']['source_date']);
                break;
            case 'extra_key': $payload['record']['untrusted'] = 'PRIVATE';
                break;
            case 'missing_evidence': unset($payload['stable_identifier_evidence']);
                break;
            case 'invalid_evidence': $payload['stable_identifier_evidence']['component_value'] = 'OTHER';
                break;
        }
        $row->update($changes + ['normalized_payload' => $payload]);
        $this->rejected(fn () => $this->service->correct($this->actor, $old->id, $proposed, 'Correction', $row->id), 'FINGERPRINT_PAYLOAD_MISMATCH');
        $this->unchanged($old, $before);
    }

    public function test_missing_predecessor_and_missing_persisted_row_follow_not_found_convention(): void
    {
        $old = $this->service->createInitial($this->actor, $this->record())->fresh();
        $before = $old->getAttributes();
        foreach ([9999, $old->id] as $id) {
            try {
                $this->service->correct($this->actor, $id, $this->record(['predicate_code' => 'CUKUP']), 'Correction', 9999);
                $this->fail('Expected persisted model not found');
            } catch (ModelNotFoundException) {
                $this->unchanged($old, $before);
            }
        }
    }

    public function test_json_key_order_is_not_part_of_provenance_integrity(): void
    {
        $old = $this->service->createInitial($this->actor, $this->record());
        $proposed = $this->record(['predicate_code' => 'CUKUP']);
        $row = $this->evidence($proposed, 'READY');
        $payload = $row->normalized_payload;
        $payload['record'] = array_reverse($payload['record'], true);
        $payload['stable_identifier_evidence'] = array_reverse($payload['stable_identifier_evidence'], true);
        $row->update(['normalized_payload' => $payload]);
        $next = $this->service->correct($this->actor, $old->id, $proposed, 'Correction', $row->id);
        $this->assertSame($old->id, $next->previous_version_id);
        $this->assertSame('READY', $row->fresh()->processing_status->value);
    }

    public function test_correction_rejects_outer_transaction_before_mutation(): void
    {
        $old = $this->service->createInitial($this->actor, $this->record())->fresh();
        $before = $old->getAttributes();
        $proposed = $this->record(['predicate_code' => 'CUKUP']);
        $row = $this->evidence($proposed);
        $sourceBefore = $row->fresh()->getAttributes();
        $batchBefore = $row->batch->getAttributes();
        $nested = 0;
        DB::transaction(function () use ($old, $proposed, $row, $before, &$nested) {
            DB::connection()->beforeStartingTransaction(function () use (&$nested) {
                $nested++;
            });
            $this->assertSame(1, DB::transactionLevel());
            $this->rejected(fn () => $this->service->correct($this->actor, $old->id, $proposed, 'Correction', $row->id), 'CORRECTION_REQUIRES_TOP_LEVEL_TRANSACTION');
            $this->unchanged($old, $before);
            $this->assertSame(1, DB::transactionLevel());
        });
        $this->assertSame(0, $nested);
        $this->assertSame($sourceBefore, $row->fresh()->getAttributes());
        $this->assertSame($batchBefore, $row->batch->fresh()->getAttributes());
        $this->unchanged($old, $before);
    }

    private function driverError(string $state = '40001', int $vendor = 1213): QueryException
    {
        $previous = new \PDOException('Synthetic driver failure', (int) $state);
        $previous->errorInfo = [$state, $vendor, $vendor === 1062 ? "Duplicate entry 'synthetic' for key 'pe_current_source_uq'" : 'Synthetic driver failure'];

        return new QueryException('testing', 'synthetic query', [], $previous);
    }

    public static function deadlockCounts(): array
    {
        return [[1], [3]];
    }

    #[DataProvider('deadlockCounts')]
    public function test_deadlock_retries_rollback_full_attempt_and_are_bounded(int $failures): void
    {
        $old = $this->service->createInitial($this->actor, $this->record())->fresh();
        $before = $old->getAttributes();
        $proposed = $this->record(['predicate_code' => 'CUKUP']);
        $row = $this->evidence($proposed);
        $attempts = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$attempts, $old, $before) {
            $this->assertSame(0, DB::transactionLevel());
            $this->unchanged($old, $before);
            $attempts++;
        });
        $calls = 0;
        // Fail after successor and audit insertion, proving the entire attempt rolls back.
        PakEvent::created(function () use (&$calls, $failures) {
            if (++$calls <= $failures) {
                throw $this->driverError();
            }
        });
        if ($failures === 3) {
            $this->rejected(fn () => $this->service->correct($this->actor, $old->id, $proposed, 'Correction', $row->id), 'CONCURRENCY_RETRY_EXHAUSTED');
            $this->unchanged($old, $before);
            $this->assertSame(3, $attempts);
        } else {
            $next = $this->service->correct($this->actor, $old->id, $proposed, 'Correction', $row->id);
            $this->assertSame(2, $attempts);
            $this->assertSame($old->id, $next->previous_version_id);
            $this->assertSame('SUPERSEDED', $old->fresh()->status->value);
            $this->assertSame('CURRENT', $next->status->value);
            $this->assertSame(1, PerformanceEvaluation::where('previous_version_id', $old->id)->count());
            $this->assertDatabaseCount('pak_events', 1);
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_retry_keeps_original_predecessor_after_competing_correction(): void
    {
        $old = $this->service->createInitial($this->actor, $this->record());
        $proposed = $this->record(['predicate_code' => 'CUKUP']);
        $row = $this->evidence($proposed);
        $fail = true;
        $compete = false;
        $successor = null;
        PakEvent::created(function () use (&$fail, &$compete) {
            if ($fail) {
                $fail = false;
                $compete = true;
                throw $this->driverError();
            }
        });
        DB::connection()->beforeStartingTransaction(function () use (&$compete, &$successor, $old, $proposed, $row) {
            if ($compete) {
                $compete = false;
                $this->assertSame(0, DB::transactionLevel());
                $successor = $this->service->correct($this->actor, $old->id, $proposed, 'Competing correction', $row->id);
            }
        });
        $this->rejected(fn () => $this->service->correct($this->actor, $old->id, $proposed, 'Original request', $row->id), 'PREDECESSOR_NOT_CURRENT');
        $this->assertSame($old->id, $successor->previous_version_id);
        $this->assertSame('CURRENT', $successor->fresh()->status->value);
        $this->assertDatabaseCount('performance_evaluations', 2);
        $this->assertDatabaseCount('pak_events', 1);
    }

    public static function nonRetryableErrors(): array
    {
        return [['23000', 1452], ['23000', 1062], ['HY000', 1205], ['HY000', 2006], ['42000', 1064]];
    }

    #[DataProvider('nonRetryableErrors')]
    public function test_correction_does_not_retry_other_database_errors(string $state, int $vendor): void
    {
        $old = $this->service->createInitial($this->actor, $this->record())->fresh();
        $before = $old->getAttributes();
        $proposed = $this->record(['predicate_code' => 'CUKUP']);
        $row = $this->evidence($proposed);
        $error = $this->driverError($state, $vendor);
        $attempts = 0;
        PakEvent::created(function () use ($error, &$attempts) {
            $attempts++;
            throw $error;
        });
        try {
            $this->service->correct($this->actor, $old->id, $proposed, 'Correction', $row->id);
            $this->fail('Expected original database error');
        } catch (QueryException $actual) {
            $this->assertSame($error, $actual);
        }
        $this->assertSame(1, $attempts);
        $this->unchanged($old, $before);
    }

    public function test_no_changes_is_not_retried(): void
    {
        $record = $this->record();
        $old = $this->service->createInitial($this->actor, $record);
        $attempts = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$attempts) {
            $attempts++;
        });
        $this->rejected(fn () => $this->service->correct($this->actor, $old->id, $record, 'Reason alone', 9999), 'NO_CHANGES');
        $this->assertSame(1, $attempts);
        $this->assertDatabaseCount('pak_events', 0);
    }

    public static function failurePoints(): array
    {
        return [['successor'], ['audit']];
    }

    #[DataProvider('failurePoints')]
    public function test_successor_or_audit_failure_rolls_back_complete_lifecycle(string $point): void
    {
        $old = $this->service->createInitial($this->actor, $this->record())->fresh();
        $before = $old->getAttributes();
        $proposed = $this->record(['predicate_code' => 'CUKUP']);
        $row = $this->evidence($proposed);
        $enabled = true;
        $called = false;
        $callback = function () use (&$enabled, &$called, $old, $point) {
            if ($enabled) {
                $called = true;
                $this->assertSame('SUPERSEDED', $old->fresh()->status->value);
                $this->assertNull($old->fresh()->current_source_identity_fingerprint);
                $this->assertDatabaseCount('performance_evaluations', $point === 'audit' ? 2 : 1);
                throw new RuntimeException('synthetic '.$point.' failure');
            }
        };
        if ($point === 'successor') {
            PerformanceEvaluation::creating($callback);
        } else {
            PakEvent::created($callback);
        }
        try {
            $this->service->correct($this->actor, $old->id, $proposed, 'Correction', $row->id);
            $this->fail('Expected injected failure');
        } catch (RuntimeException $error) {
            $this->assertSame('synthetic '.$point.' failure', $error->getMessage());
        } finally {
            $enabled = false;
        }
        $this->assertTrue($called);
        $this->unchanged($old, $before);
    }

    public function test_predecessor_query_is_locked_inside_transaction(): void
    {
        $old = $this->service->createInitial($this->actor, $this->record());
        $proposed = $this->record(['predicate_code' => 'CUKUP']);
        $row = $this->evidence($proposed);
        $level = DB::transactionLevel();
        $locks = 0;
        $scopes = PerformanceEvaluation::getAllGlobalScopes();
        PerformanceEvaluation::addGlobalScope('observe-correction-lock', function ($builder) use ($level, &$locks) {
            $builder->getQuery()->beforeQuery(function ($query) use ($level, &$locks) {
                if ($query->lock === true) {
                    $this->assertGreaterThan($level, DB::transactionLevel());
                    $locks++;
                }
            });
        });
        try {
            $this->service->correct($this->actor, $old->id, $proposed, 'Correction', $row->id);
        } finally {
            PerformanceEvaluation::setAllGlobalScopes($scopes);
        }
        $this->assertSame(1, $locks);
    }

    private function history(Person $person): EmployeePositionHistory
    {
        return EmployeePositionHistory::create(['person_id' => $person->id, 'position_name' => 'Synthetic position',
            'position_type' => 'PELAKSANA', 'tmt_start' => '2024-01-01', 'source' => 'MANUAL_VERIFIED',
            'source_ref' => 'SYNTHETIC', 'verified_by' => $this->actor->id, 'created_by' => $this->actor->id, 'verified_at' => now()]);
    }

    public function test_explicit_history_link_requires_same_person_before_any_mutation(): void
    {
        $wrong = $this->history(Person::factory()->create());
        $right = $this->history($this->person);
        try {
            $this->service->createInitial($this->actor, $this->record(), $wrong->id);
            $this->fail('Expected history ownership rejection');
        } catch (ValidationException) {
            $this->assertDatabaseCount('performance_evaluations', 0);
        }
        $old = $this->service->createInitial($this->actor, $this->record(), $right->id)->fresh();
        $this->assertSame($right->id, $old->employee_position_history_id);
        $before = $old->getAttributes();
        $proposed = $this->record(['predicate_code' => 'CUKUP']);
        $row = $this->evidence($proposed);
        try {
            $this->service->correct($this->actor, $old->id, $proposed, 'Correction', $row->id, $wrong->id);
            $this->fail('Expected history ownership rejection');
        } catch (ValidationException) {
            $this->unchanged($old, $before);
        }
        $next = $this->service->correct($this->actor, $old->id, $proposed, 'Correction', $row->id, $right->id);
        $this->assertSame($right->id, $next->employee_position_history_id);
    }

    public static function unauthorizedActors(): array
    {
        return [['inactive'], ['pak.access'], ['pak.skp.manage']];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_both_writes_authorize_before_mutation(string $missing): void
    {
        $old = $this->service->createInitial($this->actor, $this->record())->fresh();
        $before = $old->getAttributes();
        if ($missing === 'inactive') {
            $this->actor->forceFill(['is_active' => false])->save();
        } else {
            $this->actor->revokePermissionTo($missing);
        }
        foreach ([fn () => $this->service->createInitial($this->actor, $this->record(['source_record_identifier' => 'REC-002'])),
            fn () => $this->service->correct($this->actor, $old->id, $this->record(['predicate_code' => 'CUKUP']), 'Correction', 9999)] as $action) {
            try {
                $action();
                $this->fail('Expected forbidden');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
            $this->unchanged($old, $before);
        }
    }

    public function test_blank_reason_is_rejected_before_mutation(): void
    {
        $old = $this->service->createInitial($this->actor, $this->record())->fresh();
        $before = $old->getAttributes();
        foreach (['', " \t\n", "\u{00A0}"] as $reason) {
            $this->rejected(fn () => $this->service->correct($this->actor, $old->id, $this->record(['predicate_code' => 'CUKUP']), $reason, 9999), 'REVISION_REASON_REQUIRED');
            $this->unchanged($old, $before);
        }
    }

    public function test_actual_preparation_conflict_payload_can_support_correction_without_row_mutation(): void
    {
        Storage::fake('local');
        $old = $this->service->createInitial($this->actor, $this->record());
        $proposed = $this->record(['predicate_code' => 'CUKUP']);
        $source = array_diff_key(get_object_vars($proposed), ['person_id' => true, 'stable_identifier_evidence' => true]) + ['nip' => $this->person->nip];
        $stream = fopen('php://memory', 'w+b');
        try {
            fwrite($stream, json_encode([$source], JSON_THROW_ON_ERROR));
            rewind($stream);
            $batch = app(SkpImportPreparationService::class)->prepare($this->actor, new SyntheticSkpSourceAdapter, $stream, 'synthetic.json', 'local');
        } finally {
            fclose($stream);
        }
        $row = $batch->rows()->sole();
        $this->assertSame('CONFLICT', $row->processing_status->value);
        $before = $row->getAttributes();
        $this->service->correct($this->actor, $old->id, $proposed, 'Correction', $row->id);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame('READY', $batch->fresh()->status->value);
        $this->assertNull($row->fresh()->performance_evaluation_id);
    }
}
