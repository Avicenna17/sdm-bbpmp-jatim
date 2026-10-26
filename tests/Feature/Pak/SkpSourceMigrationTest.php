<?php

namespace Tests\Feature\Pak;

use App\Models\PerformanceEvaluation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\Pak\SkpSourceFixtures;
use Tests\TestCase;

class SkpSourceMigrationTest extends TestCase
{
    use SkpSourceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        if ($database !== ':memory:' && $database !== 'pak_02a1_test') {
            throw new \RuntimeException('Migration tests require an explicitly disposable database.');
        }
        // Only the three new migrations are rolled back below; do not invoke legacy down() methods.
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
    }

    public function test_fresh_rollback_upgrade_and_repeat_preserve_phase_one(): void
    {
        $conversionPaths = [
            database_path('migrations/2026_10_26_000100_create_pak_conversion_runs.php'),
            database_path('migrations/2026_10_26_000200_create_pak_conversion_periods.php'),
        ];
        foreach (array_reverse($conversionPaths) as $path) {
            (require $path)->down();
        }
        $paths = glob(database_path('migrations/2026_10_23_*.php'));
        sort($paths);
        $this->assertCount(3, $paths);
        $person = DB::table('people')->insertGetId(['person_key' => str_repeat('a', 64), 'canonical_name' => 'Synthetic', 'normalized_name' => 'SYNTHETIC']);
        $user = DB::table('users')->insertGetId(['name' => 'Synthetic', 'email' => 'migration@example.test', 'password' => 'unused', 'is_active' => true]);
        $period = DB::table('reporting_periods')->insertGetId(['period_month' => '2025-01-01', 'status' => 'published', 'revision' => 1]);
        $case = DB::table('pak_cases')->insertGetId(['person_id' => $person, 'created_by' => $user, 'updated_by' => $user, 'purpose_type' => 'KP', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'employee_data_as_of' => '2025-12-31']);
        $before = [];
        foreach (['people', 'users', 'reporting_periods', 'pak_cases'] as $table) {
            $before[$table] = DB::table($table)->get()->toArray();
        }
        for ($iteration = 0; $iteration < 2; $iteration++) {
            foreach (array_reverse($paths) as $path) {
                (require $path)->down();
            }
            foreach (['performance_evaluations', 'pak_skp_import_batches', 'pak_skp_import_rows'] as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            foreach ($paths as $path) {
                (require $path)->up();
            }
            foreach (['performance_evaluations', 'pak_skp_import_batches', 'pak_skp_import_rows'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
            foreach ($before as $table => $records) {
                $this->assertEquals($records, DB::table($table)->get()->toArray());
            }
        }
        foreach ($conversionPaths as $path) {
            (require $path)->up();
        }
        $this->assertDatabaseHas('pak_cases', ['id' => $case, 'person_id' => $person]);
        $this->assertDatabaseHas('reporting_periods', ['id' => $period, 'status' => 'published']);
    }

    public function test_mysql_concurrent_connections_enforce_current_and_predecessor_uniques(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Concurrent unique constraints require disposable MySQL/MariaDB.');
        }
        foreach (['current', 'predecessor'] as $constraint) {
            $data = $this->evaluationData(['source_identity_fingerprint' => str_repeat('f', 64), 'current_source_identity_fingerprint' => str_repeat('f', 64)]);
            if ($constraint === 'predecessor') {
                $previous = PerformanceEvaluation::create($this->evaluationData());
                $this->supersede($previous);
                $data['previous_version_id'] = $previous->id;
                $data['revision_reason'] = 'Synthetic constraint test';
                $data['source_identity_fingerprint'] = str_repeat('e', 64);
                $data['current_source_identity_fingerprint'] = str_repeat('e', 64);
            }
            $challenger = $data;
            if ($constraint === 'predecessor') {
                // Deliberately isolate the predecessor constraint from the current-identity unique.
                // Raw writes here test DB constraints only, not a valid domain write path.
                $challenger['current_source_identity_fingerprint'] = str_repeat('d', 64);
            }
            $config = DB::connection()->getConfig();
            $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4';
            $worker = new Process([PHP_BINARY, '-r', <<<'PHP'
                $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
                try {
                    $pdo = new PDO($input['dsn'], $input['user'], $input['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
                    $row = $input['row'];
                    $sql = 'INSERT INTO performance_evaluations (`'.implode('`,`', array_keys($row)).'`) VALUES ('.implode(',', array_fill(0, count($row), '?')).')';
                    echo "ATTEMPTING\n"; flush();
                    $pdo->prepare($sql)->execute(array_values($row));
                    echo "INSERTED\n";
                } catch (PDOException $e) {
                    echo ($e->errorInfo[1] ?? null) === 1062 ? "DUPLICATE\n" : "UNEXPECTED_DB_ERROR\n";
                }
                PHP]);
            $worker->setInput(json_encode(['dsn' => $dsn, 'user' => $config['username'], 'password' => $config['password'], 'row' => $challenger], JSON_THROW_ON_ERROR));
            $worker->setTimeout(10);
            DB::beginTransaction();
            try {
                $id = DB::table('performance_evaluations')->insertGetId($data);
                $worker->start();
                $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'ATTEMPTING')));
                usleep(200000);
                $this->assertTrue($worker->isRunning(), 'Competing insert must wait for the uncommitted unique key.');
                DB::commit();
                $worker->wait();
                $this->assertSame("ATTEMPTING\nDUPLICATE\n", str_replace("\r\n", "\n", $worker->getOutput()));
                $this->assertSame(0, $worker->getExitCode());
                $this->assertDatabaseHas('performance_evaluations', ['id' => $id]);
                $this->assertSame(1, DB::table('performance_evaluations')->where($constraint === 'current' ? 'current_source_identity_fingerprint' : 'previous_version_id', $constraint === 'current' ? $data['current_source_identity_fingerprint'] : $data['previous_version_id'])->count());
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
