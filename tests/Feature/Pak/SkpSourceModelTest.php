<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Enums\PerformanceEvaluationStatus;
use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;
use App\Domain\Pak\Enums\SkpImportBatchStatus;
use App\Domain\Pak\Enums\SkpImportRowStatus;
use App\Domain\Pak\Enums\SkpPersonResolutionStatus;
use App\Models\EmployeePositionHistory;
use App\Models\PakSkpImportBatch;
use App\Models\PakSkpImportRow;
use App\Models\PerformanceEvaluation;
use App\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Pak\SkpSourceFixtures;
use Tests\TestCase;

class SkpSourceModelTest extends TestCase
{
    use RefreshDatabase, SkpSourceFixtures;

    protected function rejected(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected model validation rejection.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public static function invalidEvaluations(): array
    {
        return [
            [['source_identity_fingerprint' => null]], [['content_fingerprint' => null]],
            [['content_fingerprint' => str_repeat('A', 64)]], [['content_fingerprint' => '']],
            [['current_source_identity_fingerprint' => null]], [['current_source_identity_fingerprint' => str_repeat('c', 64)]],
            [['status' => 'SUPERSEDED', 'current_source_identity_fingerprint' => null]],
            [['revision_reason' => 'Reason without predecessor']], [['revision_reason' => '']],
            [['source_reference' => '   ']], [['coverage_end' => '2024-12-31']], [['coverage_end' => '2026-01-01']],
            [['superseded_at' => '2026-01-01']], [['superseded_by_user_id' => 1]],
        ];
    }

    #[DataProvider('invalidEvaluations')]
    public function test_invalid_evaluation_is_not_persisted(array $changes): void
    {
        $data = $this->evaluationData($changes);
        $this->rejected(fn () => PerformanceEvaluation::create($data));
        $this->assertDatabaseCount('performance_evaluations', 0);
    }

    public function test_lifecycle_retains_history_and_rejects_stale_updates_and_deletion(): void
    {
        $e = PerformanceEvaluation::create($this->evaluationData());
        $stale = $e->fresh();
        $this->rejected(fn () => $e->update(['predicate_code' => 'OTHER']));
        $e->refresh();
        $this->rejected(fn () => $e->update(['status' => 'SUPERSEDED']));
        $e->refresh();
        $this->supersede($e);
        $next = PerformanceEvaluation::create($this->successorData($e));
        $this->assertTrue($next->predecessor->is($e));
        $this->assertTrue($e->successor->is($next));
        $this->assertNull($e->fresh()->current_source_identity_fingerprint);
        $this->assertSame(str_repeat('a', 64), $e->fresh()->source_identity_fingerprint);
        $this->assertSame(str_repeat('b', 64), $e->fresh()->content_fingerprint);
        $this->rejected(fn () => $stale->update(['status' => 'SUPERSEDED', 'current_source_identity_fingerprint' => null,
            'superseded_by_user_id' => $e->created_by, 'superseded_at' => now()]));
        $this->rejected(fn () => $e->update(['status' => 'CURRENT', 'current_source_identity_fingerprint' => $e->source_identity_fingerprint]));
        $this->rejected(fn () => $next->delete());
    }

    public static function invalidSuccessors(): array
    {
        return [[['person_id' => 99999]], [['performance_year' => 2024, 'coverage_start' => '2024-01-01', 'coverage_end' => '2024-12-31']],
            [['source_kind' => 'PROVISIONAL']], [['evaluation_type' => 'Q4']], [['source_reference' => 'OTHER']],
            [['source_record_identifier' => 'OTHER']], [['source_identity_fingerprint' => str_repeat('e', 64), 'current_source_identity_fingerprint' => str_repeat('e', 64)]],
            [['content_fingerprint' => str_repeat('b', 64)]], [['revision_reason' => null]], [['revision_reason' => '  ']]];
    }

    #[DataProvider('invalidSuccessors')]
    public function test_successor_preserves_identity_and_requires_changed_content_and_reason(array $changes): void
    {
        $e = PerformanceEvaluation::create($this->evaluationData());
        $this->supersede($e);
        $this->rejected(fn () => PerformanceEvaluation::create($this->successorData($e, $changes)));
        $this->assertDatabaseCount('performance_evaluations', 1);
    }

    public static function invalidHashes(): array
    {
        return [[''], [str_repeat('A', 64)], [str_repeat('g', 64)], [str_repeat('a', 63)], [str_repeat('a', 65)], [str_repeat('a', 64)."\n"]];
    }

    #[DataProvider('invalidHashes')]
    public function test_batch_and_non_null_row_hashes_reject_invalid_format(string $hash): void
    {
        $this->rejected(fn () => PakSkpImportBatch::create($this->batchData(['checksum_sha256' => $hash])));
        $batch = PakSkpImportBatch::create($this->batchData());
        foreach (['source_identity_fingerprint', 'content_fingerprint'] as $field) {
            $this->rejected(fn () => $this->pendingRow($batch, [$field => $hash]));
        }
    }

    public function test_disk_is_explicit_and_batch_commit_metadata_is_consistent(): void
    {
        $data = $this->batchData();
        unset($data['disk']);
        $this->rejected(fn () => PakSkpImportBatch::create($data));
        foreach (['committed_by', 'committed_at'] as $field) {
            $batch = PakSkpImportBatch::create($this->batchData());
            $this->rejected(fn () => $batch->update([$field => $field === 'committed_by' ? $batch->created_by : now()]));
            $batch->refresh();
            $this->rejected(fn () => $batch->update(['status' => 'COMMITTED', $field => $field === 'committed_by' ? $batch->created_by : now()]));
        }
    }

    public static function resolvedInvalidRows(): array
    {
        $cases = [];
        foreach (['READY', 'IMPORTED', 'DUPLICATE', 'CONFLICT'] as $status) {
            foreach ([['person_resolution_status' => 'PENDING'], ['person_id' => null], ['source_reference' => null],
                ['source_reference' => ''], ['source_reference' => '  '], ['source_identity_fingerprint' => null], ['content_fingerprint' => null]] as $change) {
                $cases[] = [$status, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('resolvedInvalidRows')]
    public function test_resolved_rows_require_person_reference_and_fingerprints(string $status, array $changes): void
    {
        $batch = PakSkpImportBatch::create($this->batchData());
        $e = PerformanceEvaluation::create($this->evaluationData());
        $this->rejected(fn () => PakSkpImportRow::create($this->resolvedRowData($batch, $e, array_replace(['processing_status' => $status], $changes))));
    }

    public function test_imported_duplicate_require_link_but_conflict_does_not(): void
    {
        $batch = PakSkpImportBatch::create($this->batchData());
        $e = PerformanceEvaluation::create($this->evaluationData());
        foreach (['IMPORTED', 'DUPLICATE'] as $status) {
            $this->rejected(fn () => PakSkpImportRow::create($this->resolvedRowData($batch, $e, ['processing_status' => $status, 'performance_evaluation_id' => null])));
        }
        $row = PakSkpImportRow::create($this->resolvedRowData($batch, $e, ['processing_status' => 'CONFLICT', 'performance_evaluation_id' => null]));
        $this->assertNull($row->performanceEvaluation);
        $this->rejected(fn () => $row->update(['performance_evaluation_id' => $e->id, 'person_id' => Person::factory()->create()->id]));
    }

    public function test_batch_closure_rejects_pending_ready_and_preserves_mixed_outcomes(): void
    {
        $batch = PakSkpImportBatch::create($this->batchData());
        $e = PerformanceEvaluation::create($this->evaluationData());
        $row = $this->pendingRow($batch);
        $commit = ['status' => 'COMMITTED', 'committed_by' => $batch->created_by, 'committed_at' => now()];
        $this->rejected(fn () => $batch->update($commit));
        $row->update(array_diff_key($this->resolvedRowData($batch, $e, ['processing_status' => 'READY', 'performance_evaluation_id' => null]), ['batch_id' => 1, 'record_ordinal' => 1]));
        $batch->refresh();
        $this->rejected(fn () => $batch->update($commit));
        $row->update(['processing_status' => 'DUPLICATE', 'performance_evaluation_id' => $e->id]);
        foreach (['CONFLICT', 'IDENTITY_AMBIGUOUS', 'INVALID', 'FAILED'] as $i => $status) {
            $data = $status === 'CONFLICT' ? $this->resolvedRowData($batch, $e, ['performance_evaluation_id' => null]) : ['batch_id' => $batch->id];
            PakSkpImportRow::create(array_replace($data, ['record_ordinal' => $i + 2, 'processing_status' => $status]));
        }
        $staleBatch = $batch->fresh();
        $row->load('batch');
        $batch->refresh()->update($commit);
        $this->assertCount(5, $batch->rows);
        $this->rejected(fn () => $this->pendingRow($batch, ['record_ordinal' => 99]));
        $this->rejected(fn () => $row->update(['issue_codes' => ['CHANGED']]));
        $this->rejected(fn () => $staleBatch->update(['summary' => ['changed' => true]]));
        $this->rejected(fn () => $batch->delete());
        $this->rejected(fn () => $row->delete());
    }

    public function test_provenance_is_immutable_and_invalid_rows_are_retained(): void
    {
        $batch = PakSkpImportBatch::create($this->batchData());
        foreach (['disk', 'object_key', 'original_filename', 'adapter_version', 'schema_version'] as $field) {
            $this->rejected(fn () => $batch->fresh()->update([$field => 'changed']));
        }
        $row = $this->pendingRow($batch, ['processing_status' => 'INVALID', 'raw_provenance' => ['predicate' => 'unknown']]);
        foreach (['record_ordinal' => 9, 'source_sheet' => 'Other', 'raw_provenance' => ['changed' => true], 'batch_id' => $batch->id + 1] as $field => $value) {
            $this->rejected(fn () => $row->fresh()->update([$field => $value]));
        }
        $this->assertNull($row->source_identity_fingerprint);
        $this->assertNull($row->performanceEvaluation);
        $this->rejected(fn () => $this->pendingRow($batch, ['record_ordinal' => 0]));
        $this->rejected(fn () => $this->pendingRow($batch, ['record_ordinal' => 2, 'source_row' => 0]));
    }

    public function test_relationships_casts_privacy_and_historical_enum_values(): void
    {
        $e = PerformanceEvaluation::create($this->evaluationData(['evaluation_type' => 'CUSTOM_PERIOD', 'source_kind' => 'PROVISIONAL', 'historical_reason' => 'Retensi sintetis']));
        $this->assertSame(PerformanceEvaluationType::CUSTOM_PERIOD, $e->evaluation_type);
        $this->assertSame(PerformanceSourceKind::PROVISIONAL, $e->source_kind);
        $this->assertSame(PerformanceEvaluationStatus::CURRENT, $e->status);
        $this->assertInstanceOf(CarbonImmutable::class, $e->coverage_start);
        $this->assertInstanceOf(CarbonImmutable::class, $e->created_at);
        $this->assertTrue($e->person->performanceEvaluations->first()->is($e));
        $this->assertSame($e->created_by, $e->creator->id);
        $history = EmployeePositionHistory::create(['person_id' => $e->person_id, 'position_name' => 'Sintetis', 'position_type' => 'NON_JF',
            'tmt_start' => '2024-01-01', 'source' => 'MANUAL', 'source_ref' => 'SK', 'verified_by' => $e->created_by, 'created_by' => $e->created_by, 'verified_at' => now()]);
        $other = $this->evaluationData(['source_identity_fingerprint' => str_repeat('e', 64), 'current_source_identity_fingerprint' => str_repeat('e', 64), 'employee_position_history_id' => $history->id]);
        $this->rejected(fn () => PerformanceEvaluation::create($other));
        $other['person_id'] = $e->person_id;
        $this->assertTrue(PerformanceEvaluation::create($other)->positionHistory->is($history));
        $batch = PakSkpImportBatch::create($this->batchData(['summary' => ['count' => 1], 'error_metadata' => ['code' => 'SAFE']]));
        $row = PakSkpImportRow::create($this->resolvedRowData($batch, $e, ['raw_provenance' => ['value' => 'original'], 'normalized_payload' => ['value' => 'normalized'], 'source_locator' => ['cell' => 'A2'], 'error_metadata' => ['code' => 'SAFE']]));
        $this->assertSame(SkpImportBatchStatus::PARSING, $batch->status);
        $this->assertSame(SkpImportRowStatus::DUPLICATE, $row->processing_status);
        $this->assertSame(SkpPersonResolutionStatus::MATCHED, $row->person_resolution_status);
        $this->assertSame(['cell' => 'A2'], $row->fresh()->source_locator);
        $this->assertSame(['count' => 1], $batch->fresh()->summary);
        $this->assertTrue($row->person->is($e->person));
        $this->assertTrue($row->performanceEvaluation->is($e));
        $this->assertTrue($e->importRows->first()->is($row));
        $this->assertTrue($row->batch->is($batch));
        $batch->update(['status' => 'COMMITTED', 'committed_by' => $batch->created_by, 'committed_at' => now()]);
        $this->assertTrue($batch->creator->is($batch->committer));
        $this->assertInstanceOf(CarbonImmutable::class, $batch->committed_at);
        foreach (['disk', 'object_key', 'error_metadata'] as $field) {
            $this->assertArrayNotHasKey($field, $batch->toArray());
        }
        foreach (['raw_provenance', 'normalized_payload', 'error_metadata'] as $field) {
            $this->assertArrayNotHasKey($field, $row->toArray());
        }
        $this->supersede($e);
        $this->assertTrue($e->supersededByUser->is($e->creator));
    }

    public function test_unknown_enum_value_is_rejected(): void
    {
        $this->expectException(\ValueError::class);
        new PerformanceEvaluation(['evaluation_type' => 'UNKNOWN']);
    }
}
