<?php

namespace Tests\Feature\Pak;

use App\Models\PakSkpImportBatch;
use App\Models\PakSkpImportRow;
use App\Models\PerformanceEvaluation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Pak\SkpSourceFixtures;
use Tests\TestCase;

class SkpSourceSchemaTest extends TestCase
{
    use RefreshDatabase, SkpSourceFixtures;

    public function test_schema_columns_nullability_defaults_and_indexes(): void
    {
        $expected = [
            'performance_evaluations' => ['id', 'person_id', 'performance_year', 'evaluation_type', 'source_kind', 'source_reference', 'source_record_identifier', 'predicate_code', 'predicate_label_original', 'coverage_start', 'coverage_end', 'source_date', 'source_position_name', 'source_functional_level_label', 'source_position_tmt', 'source_sk_number', 'source_sk_date', 'employee_position_history_id', 'historical_reason', 'source_identity_fingerprint', 'content_fingerprint', 'current_source_identity_fingerprint', 'status', 'previous_version_id', 'revision_reason', 'created_by', 'superseded_by_user_id', 'superseded_at', 'created_at', 'updated_at'],
            'pak_skp_import_batches' => ['id', 'disk', 'object_key', 'original_filename', 'checksum_sha256', 'adapter_key', 'adapter_version', 'schema_version', 'status', 'created_by', 'committed_by', 'committed_at', 'summary', 'error_metadata', 'created_at', 'updated_at'],
            'pak_skp_import_rows' => ['id', 'batch_id', 'record_ordinal', 'source_sheet', 'source_row', 'source_locator', 'person_id', 'person_resolution_status', 'match_method', 'source_reference', 'source_record_identifier', 'source_identity_fingerprint', 'content_fingerprint', 'normalized_payload', 'raw_provenance', 'processing_status', 'issue_codes', 'error_metadata', 'performance_evaluation_id', 'created_at', 'updated_at'],
        ];
        foreach ($expected as $table => $columns) {
            $this->assertEqualsCanonicalizing($columns, Schema::getColumnListing($table));
            foreach (Schema::getForeignKeys($table) as $fk) {
                $this->assertSame('restrict', strtolower($fk['on_delete']));
            }
        }
        $evaluation = collect(Schema::getColumns('performance_evaluations'))->keyBy('name');
        foreach (['source_identity_fingerprint', 'content_fingerprint'] as $column) {
            $this->assertFalse($evaluation[$column]['nullable']);
            $this->assertStringContainsString('char', $evaluation[$column]['type']);
        }
        foreach (['current_source_identity_fingerprint', 'previous_version_id', 'revision_reason'] as $column) {
            $this->assertTrue($evaluation[$column]['nullable']);
        }
        foreach (['status', 'evaluation_type', 'source_kind'] as $column) {
            $this->assertStringContainsString('varchar', $evaluation[$column]['type']);
        }
        $batches = collect(Schema::getColumns('pak_skp_import_batches'))->keyBy('name');
        $this->assertFalse($batches['disk']['nullable']);
        $this->assertNull($batches['disk']['default']);
        $rows = collect(Schema::getColumns('pak_skp_import_rows'))->keyBy('name');
        foreach (['person_id', 'performance_evaluation_id', 'source_identity_fingerprint', 'content_fingerprint'] as $column) {
            $this->assertTrue($rows[$column]['nullable']);
        }
        $indexes = [
            'performance_evaluations' => ['pe_current_source_uq' => true, 'pe_previous_version_uq' => true, 'pe_source_identity_idx' => false, 'pe_person_year_type_status_idx' => false, 'pe_person_coverage_idx' => false, 'pe_position_history_idx' => false, 'pe_created_by_idx' => false, 'pe_superseded_by_idx' => false],
            'pak_skp_import_batches' => ['psib_status_created_idx' => false, 'psib_checksum_idx' => false, 'psib_created_by_idx' => false, 'psib_committed_by_idx' => false],
            'pak_skp_import_rows' => ['psir_batch_ordinal_uq' => true, 'psir_person_idx' => false, 'psir_evaluation_idx' => false, 'psir_source_identity_idx' => false, 'psir_content_idx' => false],
        ];
        foreach ($indexes as $table => $names) {
            $actual = collect(Schema::getIndexes($table))->keyBy('name');
            foreach ($names as $name => $unique) {
                $this->assertSame($unique, $actual[$name]['unique']);
            }
        }
    }

    public function test_nullable_uniques_allow_multiple_initial_and_superseded_records(): void
    {
        $first = PerformanceEvaluation::create($this->evaluationData());
        $this->supersede($first);
        $second = PerformanceEvaluation::create($this->successorData($first));
        $this->supersede($second);
        $third = PerformanceEvaluation::create($this->successorData($second, ['content_fingerprint' => str_repeat('e', 64)]));
        PerformanceEvaluation::create($this->evaluationData(['source_identity_fingerprint' => str_repeat('f', 64), 'current_source_identity_fingerprint' => str_repeat('f', 64)]));
        $this->assertSame(2, PerformanceEvaluation::whereNull('previous_version_id')->count());
        $this->assertSame(2, PerformanceEvaluation::whereNull('current_source_identity_fingerprint')->count());
        $this->assertSame(3, PerformanceEvaluation::where('source_identity_fingerprint', $third->source_identity_fingerprint)->count());
    }

    public function test_database_rejects_duplicate_current_identity(): void
    {
        $data = $this->evaluationData();
        PerformanceEvaluation::create($data);
        $this->expectException(QueryException::class);
        PerformanceEvaluation::create($data);
    }

    public function test_database_rejects_second_direct_successor_even_after_first_is_superseded(): void
    {
        $first = PerformanceEvaluation::create($this->evaluationData());
        $this->supersede($first);
        $second = PerformanceEvaluation::create($this->successorData($first));
        $this->supersede($second);
        $this->expectException(QueryException::class);
        PerformanceEvaluation::create($this->successorData($first, ['content_fingerprint' => str_repeat('e', 64)]));
    }

    public function test_row_ordinal_unique_is_scoped_to_batch(): void
    {
        $a = PakSkpImportBatch::create($this->batchData());
        $b = PakSkpImportBatch::create($this->batchData());
        $this->pendingRow($a);
        $this->pendingRow($b);
        $this->expectException(QueryException::class);
        $this->pendingRow($a);
    }

    public function test_fk_restrict_prevents_raw_parent_deletion(): void
    {
        $evaluation = PerformanceEvaluation::create($this->evaluationData());
        $batch = PakSkpImportBatch::create($this->batchData());
        PakSkpImportRow::create($this->resolvedRowData($batch, $evaluation));
        foreach ([['people', $evaluation->person_id], ['users', $evaluation->created_by], ['users', $batch->created_by], ['performance_evaluations', $evaluation->id], ['pak_skp_import_batches', $batch->id]] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail('FK must restrict parent deletion');
            } catch (QueryException) {
                $this->assertDatabaseHas($table, ['id' => $id]);
            }
        }
    }

    public function test_database_not_null_is_independent_of_model_guard(): void
    {
        $data = $this->evaluationData();
        unset($data['content_fingerprint']);
        $this->expectException(QueryException::class);
        // Raw SQL here intentionally tests the constraint, not a domain write path.
        DB::table('performance_evaluations')->insert($data);
    }
}
