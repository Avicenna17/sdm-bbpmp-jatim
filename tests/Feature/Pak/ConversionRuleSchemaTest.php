<?php

namespace Tests\Feature\Pak;

use App\Models\AkCoefficientRule;
use App\Models\PakRuleSet;
use App\Models\PakSkpImportBatch;
use App\Models\PerformanceEvaluation;
use App\Models\PerformancePredicateRule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Pak\SkpSourceFixtures;
use Tests\TestCase;

class ConversionRuleSchemaTest extends TestCase
{
    use SkpSourceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $config = DB::connection()->getConfig();
        $sqlite = DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:';
        $disposable = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && getenv('PAK_DISPOSABLE_DB') === 'pak_02a1_test'
            && DB::connection()->getDatabaseName() === 'pak_02a1_test'
            && in_array($config['host'], ['127.0.0.1', 'localhost'], true)
            && (int) $config['port'] !== 3306;
        if (! $sqlite && ! $disposable) {
            throw new \RuntimeException('Schema tests require SQLite memory or explicitly disposable loopback MariaDB on a separate port.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
    }

    private function parents(): array
    {
        $user = DB::table('users')->insertGetId(['name' => 'Synthetic', 'email' => 'schema@example.test', 'password' => 'unused']);
        $rule = DB::table('pak_rule_sets')->insertGetId(['code' => 'SYNTHETIC', 'name' => 'Synthetic', 'version' => '1', 'created_by' => $user, 'updated_by' => $user]);
        $other = DB::table('pak_rule_sets')->insertGetId(['code' => 'SYNTHETIC', 'name' => 'Synthetic', 'version' => '2', 'created_by' => $user, 'updated_by' => $user]);
        $category = DB::table('functional_categories')->insertGetId(['code' => 'SYNTHETIC', 'name' => 'Synthetic']);
        $level = DB::table('functional_levels')->insertGetId(['functional_category_id' => $category, 'code' => 'SYNTHETIC', 'name' => 'Synthetic']);

        return [$rule, $other, $level];
    }

    private function violatesConstraint(callable $write): void
    {
        try {
            $write();
            $this->fail('Expected database constraint violation.');
        } catch (QueryException $error) {
            // MariaDB reports an omitted required/no-default column as HY000/1364.
            $missingDefault = (string) $error->errorInfo[0] === 'HY000' && (int) $error->errorInfo[1] === 1364;
            $this->assertTrue((string) $error->errorInfo[0] === '23000' || $missingDefault);
        }
    }

    public function test_columns_fk_indexes_decimal_and_collation_contract(): void
    {
        $spec = [
            'performance_predicate_rules' => ['predicate_code', 'predicate_name', 'predicate_factor'],
            'ak_coefficient_rules' => ['functional_level_id', 'annual_coefficient'],
        ];
        foreach ($spec as $table => $fields) {
            $this->assertEqualsCanonicalizing(['id', 'pak_rule_set_id', ...$fields, 'created_at', 'updated_at'], Schema::getColumnListing($table));
            $columns = collect(Schema::getColumns($table))->keyBy('name');
            foreach (['pak_rule_set_id', ...$fields] as $field) {
                $this->assertFalse($columns[$field]['nullable']);
                $this->assertNull($columns[$field]['default']);
            }
            $fks = Schema::getForeignKeys($table);
            $this->assertCount($table === 'ak_coefficient_rules' ? 2 : 1, $fks);
            foreach ($fks as $fk) {
                $this->assertSame('restrict', strtolower($fk['on_delete']));
                $this->assertSame(['id'], $fk['foreign_columns']);
                $this->assertSame($fk['columns'] === ['pak_rule_set_id'] ? 'pak_rule_sets' : 'functional_levels', $fk['foreign_table']);
            }
        }
        foreach ([['performance_predicate_rules', 'ppr_rule_code_uq', ['pak_rule_set_id', 'predicate_code']], ['ak_coefficient_rules', 'acr_rule_level_uq', ['pak_rule_set_id', 'functional_level_id']]] as [$table, $name, $columns]) {
            $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);
            $this->assertTrue($index['unique']);
            $this->assertSame($columns, $index['columns']);
        }
        $levelIndexes = collect(Schema::getIndexes('ak_coefficient_rules'))->filter(fn ($index) => $index['columns'] === ['functional_level_id']);
        $this->assertCount(1, $levelIndexes, 'No redundant auto-created FK index beside the explicit index.');
        $this->assertSame('acr_level_idx', $levelIndexes->first()['name']);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach ([['performance_predicate_rules', 'predicate_factor', 8, 4], ['ak_coefficient_rules', 'annual_coefficient', 15, 6]] as [$table, $column, $precision, $scale]) {
                $metadata = DB::selectOne('SELECT NUMERIC_PRECISION AS p, NUMERIC_SCALE AS s, COLUMN_DEFAULT AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
                $this->assertSame($precision, (int) $metadata->p);
                $this->assertSame($scale, (int) $metadata->s);
                $this->assertNull($metadata->d);
            }
            $columns = collect(Schema::getColumns('performance_predicate_rules'))->keyBy('name');
            $this->assertSame('utf8mb4_bin', $columns['predicate_code']['collation']);
            $this->assertSame(config('database.connections.'.DB::getDefaultConnection().'.collation'), $columns['predicate_name']['collation']);
            fwrite(STDERR, "\nRULE_SCHEMA_DB ".DB::selectOne('SELECT VERSION() AS version')->version."\n");
        } else {
            // Laravel SQLite grammar stores NUMERIC affinity, not enforced DECIMAL precision/scale.
            $this->assertSame('numeric', collect(Schema::getColumns('performance_predicate_rules'))->firstWhere('name', 'predicate_factor')['type_name']);
        }
    }

    public function test_raw_unique_pairs_are_case_sensitive_and_scoped_to_parent(): void
    {
        [$rule, $other, $level] = $this->parents();
        $data = ['pak_rule_set_id' => $rule, 'predicate_code' => 'BAIK', 'predicate_name' => 'Baik', 'predicate_factor' => '1.0000'];
        DB::table('performance_predicate_rules')->insert($data);
        DB::table('performance_predicate_rules')->insert(array_replace($data, ['predicate_code' => 'baik']));
        DB::table('performance_predicate_rules')->insert(array_replace($data, ['pak_rule_set_id' => $other]));
        $this->assertSame(1, DB::table('performance_predicate_rules')->where('pak_rule_set_id', $rule)->where('predicate_code', 'BAIK')->count());
        $this->assertSame(1, DB::table('performance_predicate_rules')->where('pak_rule_set_id', $rule)->where('predicate_code', 'baik')->count());
        $this->assertSame(0, DB::table('performance_predicate_rules')->where('pak_rule_set_id', $rule)->where('predicate_code', 'Baik')->count());
        $this->violatesConstraint(fn () => DB::table('performance_predicate_rules')->insert($data));
        $data = ['pak_rule_set_id' => $rule, 'functional_level_id' => $level, 'annual_coefficient' => '37.500000'];
        DB::table('ak_coefficient_rules')->insert($data);
        DB::table('ak_coefficient_rules')->insert(array_replace($data, ['pak_rule_set_id' => $other]));
        $this->violatesConstraint(fn () => DB::table('ak_coefficient_rules')->insert($data));
        $this->assertDatabaseCount('performance_predicate_rules', 3);
        $this->assertDatabaseCount('ak_coefficient_rules', 2);
    }

    public function test_fk_restrict_and_numeric_not_null_without_model_guards(): void
    {
        [$rule, , $level] = $this->parents();
        $predicate = ['pak_rule_set_id' => $rule, 'predicate_code' => 'BAIK', 'predicate_name' => 'Baik', 'predicate_factor' => '1'];
        $coefficient = ['pak_rule_set_id' => $rule, 'functional_level_id' => $level, 'annual_coefficient' => '25'];
        foreach ([['performance_predicate_rules', $predicate, 'predicate_factor'], ['ak_coefficient_rules', $coefficient, 'annual_coefficient']] as [$table, $data, $numeric]) {
            $missing = $data;
            unset($missing[$numeric]);
            $this->violatesConstraint(fn () => DB::table($table)->insert($missing));
            $this->violatesConstraint(fn () => DB::table($table)->insert(array_replace($data, ['pak_rule_set_id' => 999999])));
        }
        $this->violatesConstraint(fn () => DB::table('ak_coefficient_rules')->insert(array_replace($coefficient, ['functional_level_id' => 999999])));
        DB::table('performance_predicate_rules')->insert($predicate);
        $this->violatesConstraint(fn () => DB::table('pak_rule_sets')->where('id', $rule)->delete());
        DB::table('ak_coefficient_rules')->insert($coefficient);
        $this->violatesConstraint(fn () => DB::table('functional_levels')->where('id', $level)->delete());
        // Raw deletion only isolates each FK in schema tests, never a domain write path.
        DB::table('performance_predicate_rules')->delete();
        $this->violatesConstraint(fn () => DB::table('pak_rule_sets')->where('id', $rule)->delete());
        $this->assertDatabaseHas('pak_rule_sets', ['id' => $rule]);
        $this->assertDatabaseHas('functional_levels', ['id' => $level]);
    }

    public function test_exact_decimal_cast_roundtrip_on_schema_values(): void
    {
        [$rule, , $level] = $this->parents();
        $predicate = PerformancePredicateRule::create(['pak_rule_set_id' => $rule, 'predicate_code' => 'SYNTHETIC', 'predicate_name' => 'Synthetic', 'predicate_factor' => '9999.9999']);
        $coefficient = AkCoefficientRule::create(['pak_rule_set_id' => $rule, 'functional_level_id' => $level, 'annual_coefficient' => '37.5']);
        $this->assertSame('9999.9999', $predicate->fresh()->predicate_factor);
        $this->assertSame('37.500000', $coefficient->fresh()->annual_coefficient);
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $coefficient->update(['annual_coefficient' => '999999999.999999']);
            $this->assertSame('999999999.999999', $coefficient->fresh()->annual_coefficient);
            $this->assertSame('999999999.999999', DB::table('ak_coefficient_rules')->value('annual_coefficient'));
        }
    }

    public function test_rollback_and_upgrade_from_phase_02a_preserve_existing_records(): void
    {
        $paths = [
            database_path('migrations/2026_10_25_000100_create_performance_predicate_rules.php'),
            database_path('migrations/2026_10_25_000200_create_ak_coefficient_rules.php'),
        ];
        foreach (array_reverse($paths) as $path) {
            (require $path)->down();
        }
        [$rule] = $this->parents();
        $evaluation = PerformanceEvaluation::create($this->evaluationData());
        $batch = PakSkpImportBatch::create($this->batchData());
        $this->pendingRow($batch);
        PakRuleSet::findOrFail($rule)->forceFill(['first_used_at' => now()])->save();
        $case = DB::table('pak_cases')->insertGetId(['person_id' => $evaluation->person_id, 'pak_rule_set_id' => $rule, 'created_by' => $evaluation->created_by, 'updated_by' => $evaluation->created_by, 'purpose_type' => 'KP', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'employee_data_as_of' => '2025-12-31']);
        $before = [];
        foreach (['people', 'users', 'pak_rule_sets', 'pak_cases', 'performance_evaluations', 'pak_skp_import_batches', 'pak_skp_import_rows'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }
        for ($iteration = 0; $iteration < 2; $iteration++) {
            $this->assertFalse(Schema::hasTable('performance_predicate_rules'));
            $this->assertFalse(Schema::hasTable('ak_coefficient_rules'));
            foreach ($paths as $path) {
                (require $path)->up();
            }
            $this->assertDatabaseCount('performance_predicate_rules', 0);
            $this->assertDatabaseCount('ak_coefficient_rules', 0);
            foreach ($before as $table => $records) {
                $this->assertEquals($records, DB::table($table)->orderBy('id')->get()->toArray());
            }
            if ($iteration === 0) {
                foreach (array_reverse($paths) as $path) {
                    (require $path)->down();
                }
            }
        }
        $this->assertDatabaseHas('pak_cases', ['id' => $case, 'pak_rule_set_id' => $rule]);
    }
}
