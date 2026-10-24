<?php

namespace Tests\Support\Pak;

use App\Models\PakSkpImportBatch;
use App\Models\PakSkpImportRow;
use App\Models\PerformanceEvaluation;
use App\Models\Person;
use App\Models\User;

trait SkpSourceFixtures
{
    protected function evaluationData(array $overrides = []): array
    {
        return array_replace([
            'person_id' => Person::factory()->create()->id, 'created_by' => User::factory()->create()->id,
            'performance_year' => 2025, 'evaluation_type' => 'ANNUAL', 'source_kind' => 'OFFICIAL',
            'source_reference' => 'SKP-001', 'source_record_identifier' => null,
            'predicate_code' => 'BAIK', 'predicate_label_original' => 'Baik',
            'coverage_start' => '2025-01-01', 'coverage_end' => '2025-12-31',
            // Explicit synthetic values, not a substitute canonical serializer.
            'source_identity_fingerprint' => str_repeat('a', 64), 'content_fingerprint' => str_repeat('b', 64),
            'current_source_identity_fingerprint' => str_repeat('a', 64),
        ], $overrides);
    }

    protected function batchData(array $overrides = []): array
    {
        return array_replace(['disk' => 'local', 'object_key' => 'synthetic/private.xlsx', 'original_filename' => 'fixture.xlsx',
            'checksum_sha256' => str_repeat('c', 64), 'adapter_key' => 'synthetic', 'adapter_version' => 'v1',
            'schema_version' => 'v1', 'created_by' => User::factory()->create()->id], $overrides);
    }

    protected function pendingRow(PakSkpImportBatch $batch, array $overrides = []): PakSkpImportRow
    {
        return PakSkpImportRow::create(array_replace(['batch_id' => $batch->id, 'record_ordinal' => 1], $overrides));
    }

    protected function resolvedRowData(PakSkpImportBatch $batch, PerformanceEvaluation $evaluation, array $overrides = []): array
    {
        return array_replace(['batch_id' => $batch->id, 'record_ordinal' => 1, 'processing_status' => 'DUPLICATE',
            'person_resolution_status' => 'MATCHED', 'person_id' => $evaluation->person_id,
            'source_reference' => $evaluation->source_reference, 'source_identity_fingerprint' => $evaluation->source_identity_fingerprint,
            'content_fingerprint' => $evaluation->content_fingerprint, 'performance_evaluation_id' => $evaluation->id], $overrides);
    }

    protected function supersede(PerformanceEvaluation $evaluation): void
    {
        $evaluation->update(['status' => 'SUPERSEDED', 'current_source_identity_fingerprint' => null,
            'superseded_by_user_id' => $evaluation->created_by, 'superseded_at' => now()]);
    }

    protected function successorData(PerformanceEvaluation $previous, array $overrides = []): array
    {
        return array_replace($previous->only(['person_id', 'created_by', 'performance_year', 'evaluation_type', 'source_kind',
            'source_reference', 'source_record_identifier', 'predicate_code', 'predicate_label_original', 'coverage_start', 'coverage_end', 'source_identity_fingerprint']),
            ['previous_version_id' => $previous->id, 'revision_reason' => 'Koreksi sintetis', 'content_fingerprint' => str_repeat('d', 64),
                'current_source_identity_fingerprint' => $previous->source_identity_fingerprint], $overrides);
    }
}
