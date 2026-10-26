<?php

namespace Tests\Feature\Pak;

use App\Models\PakConversionPeriod;
use App\Models\PakConversionRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Pak\ConversionRunFixtures;
use Tests\TestCase;

class ConversionRunSchemaTest extends TestCase
{
    use ConversionRunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $config = DB::connection()->getConfig();
        $safe = DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:';
        $safe = $safe || (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && getenv('PAK_DISPOSABLE_DB') === 'pak_02a1_test' && DB::connection()->getDatabaseName() === 'pak_02a1_test' && in_array($config['host'], ['127.0.0.1', 'localhost'], true) && (int) $config['port'] !== 3306);
        if (! $safe) {
            throw new \RuntimeException('Disposable database required.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
    }

    private function violates(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected database constraint rejection.');
        } catch (QueryException $e) {
            $this->assertSame('23000', (string) $e->errorInfo[0]);
        }
    }

    public function test_columns_nullability_fk_restrict_and_unique_indexes(): void
    {
        $runColumns = ['id', 'pak_case_id', 'person_id', 'pak_rule_set_id', 'revision_number', 'predecessor_run_id', 'revision_reason', 'status', 'active_case_id', 'requested_start', 'requested_end', 'case_lock_version', 'dependency_fingerprint', 'snapshot_fingerprint', 'snapshot_schema_version', 'calculation_contract_version', 'rounding_profile', 'rounding_version', 'dependency_manifest', 'original_issues', 'calculation_trace', 'total_numerator', 'total_denominator', 'review_assessment', 'review_assessed_by', 'review_assessed_at', 'superseded_by_user_id', 'superseded_at', 'created_by', 'created_at', 'updated_at'];
        $periodColumns = ['id', 'pak_conversion_run_id', 'line_number', 'period_start', 'period_end', 'month_count', 'performance_evaluation_id', 'source_position_history_id', 'employee_position_history_id', 'functional_level_id', 'performance_predicate_rule_id', 'ak_coefficient_rule_id', 'predicate_factor', 'annual_coefficient', 'result_numerator', 'result_denominator', 'source_snapshot', 'position_snapshot', 'rule_snapshot', 'original_issues', 'calculation_trace', 'created_at', 'updated_at'];
        foreach (['pak_conversion_runs' => $runColumns, 'pak_conversion_periods' => $periodColumns] as $table => $fields) {
            $this->assertEqualsCanonicalizing($fields, Schema::getColumnListing($table));
            foreach (Schema::getForeignKeys($table) as $fk) {
                $this->assertSame('restrict', strtolower($fk['on_delete']));
            }
            $columns = collect(Schema::getColumns($table))->keyBy('name');
            $nullable = $table === 'pak_conversion_runs'
                ? ['predecessor_run_id', 'revision_reason', 'active_case_id', 'total_numerator', 'total_denominator', 'review_assessment', 'review_assessed_by', 'review_assessed_at', 'superseded_by_user_id', 'superseded_at', 'created_at', 'updated_at']
                : ['performance_evaluation_id', 'source_position_history_id', 'employee_position_history_id', 'functional_level_id', 'performance_predicate_rule_id', 'ak_coefficient_rule_id', 'predicate_factor', 'annual_coefficient', 'result_numerator', 'result_denominator', 'source_snapshot', 'position_snapshot', 'rule_snapshot', 'created_at', 'updated_at'];
            foreach ($fields as $field) {
                $this->assertSame(in_array($field, $nullable, true), $columns[$field]['nullable'], $table.'.'.$field);
            }
            foreach (['original_issues', 'calculation_trace'] as $field) {
                $this->assertFalse($columns[$field]['nullable']);
            }
            foreach ($columns as $name => $column) {
                if (str_ends_with($name, '_numerator') || str_ends_with($name, '_denominator')) {
                    $this->assertSame('text', $column['type_name']);
                    $this->assertTrue($column['nullable']);
                }
            }
        }
        $this->assertCount(8, Schema::getForeignKeys('pak_conversion_runs'));
        $this->assertCount(7, Schema::getForeignKeys('pak_conversion_periods'));
        foreach ([
            'pak_conversion_runs' => ['pak_case_id' => 'pak_cases', 'person_id' => 'people', 'pak_rule_set_id' => 'pak_rule_sets', 'predecessor_run_id' => 'pak_conversion_runs', 'active_case_id' => 'pak_cases', 'created_by' => 'users', 'review_assessed_by' => 'users', 'superseded_by_user_id' => 'users'],
            'pak_conversion_periods' => ['pak_conversion_run_id' => 'pak_conversion_runs', 'performance_evaluation_id' => 'performance_evaluations', 'source_position_history_id' => 'employee_position_histories', 'employee_position_history_id' => 'employee_position_histories', 'functional_level_id' => 'functional_levels', 'performance_predicate_rule_id' => 'performance_predicate_rules', 'ak_coefficient_rule_id' => 'ak_coefficient_rules'],
        ] as $table => $references) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                $this->assertSame($references[$fk['columns'][0]], $fk['foreign_table']);
                $this->assertSame(['id'], $fk['foreign_columns']);
            }
        }
        $columns = collect(Schema::getColumns('pak_conversion_periods'))->keyBy('name');
        foreach (['performance_evaluation_id', 'source_position_history_id', 'employee_position_history_id', 'functional_level_id', 'performance_predicate_rule_id', 'ak_coefficient_rule_id', 'source_snapshot', 'position_snapshot', 'rule_snapshot', 'predicate_factor', 'annual_coefficient'] as $field) {
            $this->assertTrue($columns[$field]['nullable']);
            $this->assertContains($columns[$field]['default'], [null, 'NULL']);
        }
        foreach ([['pak_conversion_runs', 'pcr_case_revision_uq', ['pak_case_id', 'revision_number']], ['pak_conversion_runs', 'pcr_predecessor_uq', ['predecessor_run_id']], ['pak_conversion_runs', 'pcr_active_case_uq', ['active_case_id']], ['pak_conversion_periods', 'pcp_run_line_uq', ['pak_conversion_run_id', 'line_number']]] as [$table, $name, $fields]) {
            $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);
            $this->assertTrue($index['unique']);
            $this->assertSame($fields, $index['columns']);
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach ([['predicate_factor', 8, 4], ['annual_coefficient', 15, 6]] as [$field, $precision, $scale]) {
                $column = DB::selectOne('SELECT NUMERIC_PRECISION AS p, NUMERIC_SCALE AS s FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['pak_conversion_periods', $field]);
                $this->assertSame($precision, (int) $column->p);
                $this->assertSame($scale, (int) $column->s);
            }
            fwrite(STDERR, "\nCONVERSION_SCHEMA_DB ".DB::selectOne('SELECT VERSION() AS version')->version."\n");
        }
    }

    public function test_raw_uniques_and_restrict_without_model_guards(): void
    {
        $run = PakConversionRun::create($this->runData());
        $raw = $run->getAttributes();
        unset($raw['id']);
        $this->violates(fn () => DB::table('pak_conversion_runs')->insert($raw));
        $this->violates(fn () => DB::table('pak_conversion_runs')->insert(array_replace($raw, ['revision_number' => 2])));
        $historical = array_replace($raw, ['status' => 'SUPERSEDED', 'active_case_id' => null]);
        $first = DB::table('pak_conversion_runs')->insertGetId(array_replace($historical, ['revision_number' => 2, 'predecessor_run_id' => $run->id]));
        $this->violates(fn () => DB::table('pak_conversion_runs')->insert(array_replace($historical, ['revision_number' => 3, 'predecessor_run_id' => $run->id])));
        DB::table('pak_conversion_runs')->insert(array_replace($historical, ['revision_number' => 3, 'predecessor_run_id' => $first]));
        $line = PakConversionPeriod::create($this->diagnosticData($run->id));
        $lineRaw = $line->getAttributes();
        unset($lineRaw['id']);
        $this->violates(fn () => DB::table('pak_conversion_periods')->insert($lineRaw));
        $this->violates(fn () => DB::table('pak_conversion_runs')->where('id', $run->id)->delete());
        $this->violates(fn () => DB::table('pak_cases')->where('id', $run->pak_case_id)->delete());
        $this->assertDatabaseCount('pak_conversion_runs', 3);
    }

    public function test_upgrade_rollback_preserves_accepted_baseline(): void
    {
        $data = $this->runData();
        $before = DB::table('pak_cases')->get()->toArray();
        $paths = [
            database_path('migrations/2026_10_26_000100_create_pak_conversion_runs.php'),
            database_path('migrations/2026_10_26_000200_create_pak_conversion_periods.php'),
        ];
        foreach (array_reverse($paths) as $path) {
            (require $path)->down();
        }
        $this->assertFalse(Schema::hasTable('pak_conversion_runs'));
        $this->assertFalse(Schema::hasTable('pak_conversion_periods'));
        foreach ($paths as $path) {
            (require $path)->up();
        }
        $this->assertEquals($before, DB::table('pak_cases')->get()->toArray());
        $this->assertSame($data['pak_case_id'], PakConversionRun::create($data)->pak_case_id);
    }
}
