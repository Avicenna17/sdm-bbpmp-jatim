<?php

// Test-only independent process. Connection credentials are accepted only over stdin.
use App\Domain\Pak\Services\ConversionRuleService;
use App\Domain\Pak\Services\PakCaseService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($input['db']['database'] ?? null) !== 'pak_02a1_test' || ($input['db']['host'] ?? null) !== '127.0.0.1'
    || (int) ($input['db']['port'] ?? 3306) === 3306) {
    throw new RuntimeException('Disposable connection required.');
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql' => $input['db'], 'cache.default' => 'array', 'logging.default' => 'null']);
DB::purge('mysql');
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($input['root'].'/'.$input['worker'].'.connection', (string) $connection);
$held = false;
if ($input['hold']) {
    DB::listen(function ($event) use ($input, &$held) {
        if (! $held && str_contains($event->sql, 'pak_rule_sets') && str_contains($event->sql, 'for update')) {
            $held = true;
            touch($input['root'].'/held');
            $deadline = microtime(true) + 15;
            while (! is_file($input['root'].'/release')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('BARRIER_TIMEOUT');
                }
                usleep(10000);
            }
        }
    });
}
$result = ['success' => false, 'issue_code' => null, 'connection_id' => $connection, 'pid' => getmypid()];
try {
    $actor = User::findOrFail($input['actor']);
    if ($input['operation'] === 'case') {
        app(PakCaseService::class)->create($actor, $input['case']);
    } else {
        app(ConversionRuleService::class)->{'save'.ucfirst($input['kind'])}($actor, $input['rule'], $input['data'], $input['child']);
    }
    $result['success'] = true;
} catch (Throwable $error) {
    $result['issue_code'] = $error instanceof ValidationException
        ? (array_values($error->errors())[0][0] ?? 'VALIDATION_FAILED') : 'UNEXPECTED_FAILURE';
}
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
