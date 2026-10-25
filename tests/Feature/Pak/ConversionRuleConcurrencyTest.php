<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Services\ConversionRuleService;
use App\Domain\Pak\Services\PakRuleSetService;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\PakEvent;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConversionRuleConcurrencyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PAK_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Opt in with PAK_CONCURRENCY=1 and disposable MySQL configuration.');
        }
        $config = DB::connection()->getConfig();
        $this->assertSame('mysql', $config['driver']);
        $this->assertSame('pak_02a1_test', $config['database']);
        $this->assertSame('pak_02a1_test', getenv('PAK_DISPOSABLE_DB'));
        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertNotSame(3306, (int) $config['port']);
        $this->assertSame((int) getenv('PAK_CONCURRENCY_PORT'), (int) $config['port']);
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->seed();
        $this->root = storage_path('framework/testing/rule-concurrency-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    private function await(callable $condition): void
    {
        $deadline = microtime(true) + 12;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $this->fail('Test barrier/lock-wait timeout.');
            }
            usleep(200000);
        }
    }

    public static function orderings(): array
    {
        return [['predicate', false], ['predicate', true], ['coefficient', false], ['coefficient', true]];
    }

    #[DataProvider('orderings')]
    public function test_parent_lock_serializes_child_write_and_case_first_use(string $kind, bool $caseFirst): void
    {
        $actor = User::factory()->create()->assignRole('Admin PAK');
        $person = Person::factory()->create();
        $rule = app(PakRuleSetService::class)->save($actor, ['code' => 'SYNTHETIC', 'name' => 'Fixture', 'version' => '1']);
        if ($kind === 'predicate') {
            $data = ['predicate_code' => 'BAIK', 'predicate_name' => 'Synthetic', 'predicate_factor' => '1'];
            $field = 'predicate_factor';
        } else {
            $category = FunctionalCategory::create(['code' => 'SYNTHETIC', 'name' => 'Synthetic']);
            $level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'SYNTHETIC', 'name' => 'Synthetic']);
            $data = ['functional_level_id' => $level->id, 'annual_coefficient' => '1'];
            $field = 'annual_coefficient';
        }
        $child = app(ConversionRuleService::class)->{'save'.ucfirst($kind)}($actor, $rule->id, $data);
        $copy = app(PakRuleSetService::class)->copyVersion($actor, $rule->id, 'copy');
        $data[$field] = '2';
        $input = ['db' => DB::connection()->getConfig(), 'root' => $this->root, 'actor' => $actor->id,
            'rule' => $rule->id, 'child' => $child->id, 'kind' => $kind, 'data' => $data,
            'case' => ['person_id' => $person->id, 'purpose_type' => 'KP', 'period_start' => '2020-01-01', 'period_end' => '2026-10-01', 'employee_data_as_of' => '2026-10-01', 'pak_rule_set_id' => $rule->id]];
        $workers = [];
        foreach ($caseFirst ? ['case', 'child'] : ['child', 'case'] as $index => $operation) {
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/Pak/rule-concurrency-worker.php')], base_path(),
                ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => 'bootstrap/cache/rule-test-unused-config.php'],
                json_encode($input + ['operation' => $operation, 'worker' => $index, 'hold' => $index === 0], JSON_THROW_ON_ERROR), 25);
        }
        try {
            $workers[0]->start();
            $this->await(fn () => is_file($this->root.'/held'));
            $workers[1]->start();
            $this->await(fn () => is_file($this->root.'/1.connection'));
            $ids = [(int) file_get_contents($this->root.'/0.connection'), (int) file_get_contents($this->root.'/1.connection')];
            $this->assertNotSame($ids[0], $ids[1]);
            $this->await(function () use ($ids) {
                $waits = DB::select('SELECT r.trx_mysql_thread_id AS waiter, b.trx_mysql_thread_id AS blocker FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id');
                foreach ($waits as $wait) {
                    if ((int) $wait->waiter === $ids[1] && (int) $wait->blocker === $ids[0]) {
                        return true;
                    }
                }

                return false;
            });
            touch($this->root.'/release');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), 'Worker failed; private stderr omitted.');
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            $this->assertTrue($results[0]['success']);
            $this->assertSame(! $caseFirst, $results[1]['success']);
            $this->assertSame($caseFirst ? 'RULE_SET_NOT_EDITABLE' : null, $results[1]['issue_code']);
            DB::purge();
            $this->assertNotNull($rule->fresh()->first_used_at);
            $this->assertSame($caseFirst ? '1' : '2', rtrim(rtrim($child->fresh()->$field, '0'), '.'));
            $relation = $kind === 'predicate' ? 'performancePredicateRules' : 'akCoefficientRules';
            $this->assertSame('1', rtrim(rtrim($copy->$relation()->sole()->$field, '0'), '.'));
            $this->assertNull($copy->fresh()->first_used_at);
            $this->assertDatabaseCount('pak_cases', 1);
            $this->assertSame(1, PakEvent::where('event_code', 'PAK_CASE_CREATED')->count());
            $this->assertSame($caseFirst ? 0 : 1, PakEvent::where('event_code', strtoupper($kind).'_RULE_UPDATED')->count());
            $this->assertSame($caseFirst ? 4 : 5, PakEvent::count());
            fwrite(STDOUT, "\nRULE_CONCURRENCY ".json_encode(['version' => DB::selectOne('SELECT VERSION() AS v')->v, 'kind' => $kind, 'case_first' => $caseFirst, 'lock_wait_observed' => true, 'results' => $results], JSON_THROW_ON_ERROR)."\n");
        } finally {
            touch($this->root.'/release');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }
    }
}
