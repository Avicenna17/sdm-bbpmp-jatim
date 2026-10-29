<?php

// Test-only process entrypoint. Credentials arrive on stdin and are never returned.
use App\Domain\Import\ImportService;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\EmployeeHistoryService;
use App\Domain\Pak\Services\FunctionalMasterService;
use App\Domain\Pak\Services\PakCaseService;
use App\Domain\Pak\Services\PerformanceEvaluationService;
use App\Domain\Pak\Services\SkpFingerprintSerializer;
use App\Domain\Pak\Services\SkpImportCommitService;
use App\Models\ImportBatch;
use App\Models\PerformanceEvaluation;
use App\Models\Person;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Pak\ConcurrencyConnection;
use Tests\Support\Pak\SyntheticSkpSourceAdapter;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($input['db']['database'] ?? null) !== 'pak_02a1_test' || ($input['db']['host'] ?? null) !== '127.0.0.1'
    || (int) ($input['db']['port'] ?? 3306) === 3306) {
    throw new RuntimeException('Disposable connection required.');
}
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql' => $input['db'],
    'cache.default' => 'array', 'logging.default' => 'null',
    'filesystems.disks.local' => ['driver' => 'local', 'root' => $input['root'], 'visibility' => 'private', 'throw' => false]]);
Connection::resolverFor('mysql', fn ($pdo, $database, $prefix, $config) => new ConcurrencyConnection($pdo, $database, $prefix, $config));
DB::purge('mysql');
if ($input['operation'] === 'conversion') {
    if (getenv('PAK_CONCURRENCY') !== '1' || getenv('PAK_DISPOSABLE_DB') !== 'pak_02a1_test'
        || (int) $input['db']['port'] !== 33318
        || DB::selectOne('SELECT DATABASE() AS db')->db !== 'pak_02a1_test'
        || ! str_starts_with(DB::selectOne('SELECT VERSION() AS v')->v, '10.5.11-MariaDB')) {
        throw new RuntimeException('Verified disposable MariaDB required.');
    }
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
$connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$signal = function (string $name) use ($input): void {
    file_put_contents($input['root'].'/'.$input['worker'].'.'.$name, 'ready');
};
$wait = function () use ($input): void {
    $deadline = microtime(true) + 15;
    while (! is_file($input['root'].'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('BARRIER_TIMEOUT');
        }
        usleep(10000);
    }
};
file_put_contents($input['root'].'/'.$input['worker'].'.connection', (string) $connectionId);
$once = true;
if ($input['barrier'] === 'create') {
    PerformanceEvaluation::creating(function () use (&$once, $signal, $wait) {
        if ($once) {
            $once = false;
            $signal('held');
            $wait();
        }
    });
} elseif ($input['barrier'] === 'hold') {
    DB::listen(function ($event) use (&$once, $signal, $wait, $input) {
        if ($once && (str_contains($event->sql, 'for update') || str_contains($event->sql, 'lock in share mode')) && str_contains($event->sql, $input['lock_table'])) {
            $once = false;
            $signal('held');
            $wait();
        }
    });
}
$result = ['success' => false, 'issue_code' => null, 'batch_id' => $input['batch_id'] ?? null,
    'status' => null, 'evaluation_id' => null, 'connection_id' => $connectionId, 'pid' => getmypid()];
try {
    $actor = User::findOrFail($input['actor']);
    if ($input['operation'] === 'conversion') {
        $result['attempts'] = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$result) {
            $result['attempts']++;
        });
        $run = app(ConversionService::class)->saveInitial($actor, ConversionSaveRequest::from($input['request']));
        $result['run_id'] = $run->id;
        $result['status'] = $run->status->value;
    } elseif ($input['operation'] === 'commit') {
        $batch = app(SkpImportCommitService::class)->commit($actor, $input['batch_id']);
        $result += ['committed_by' => $batch->committed_by, 'committed_at' => $batch->committed_at->format('Y-m-d H:i:s'),
            'summary' => $batch->summary, 'rows' => $batch->rows()->orderBy('record_ordinal')->get()->map(fn ($row) => ['id' => $row->id, 'status' => $row->processing_status->value, 'evaluation_id' => $row->performance_evaluation_id])->all()];
        $result['status'] = $batch->status->value;
    } elseif ($input['operation'] === 'history') {
        $history = app(EmployeeHistoryService::class)->record($actor, Person::findOrFail($input['person']), $input['history'], true);
        $result['history_id'] = $history->id;
    } elseif ($input['operation'] === 'duk') {
        app(ImportService::class)->commit($actor, ImportBatch::findOrFail($input['batch_id']));
        $result['status'] = ImportBatch::findOrFail($input['batch_id'])->status;
    } elseif ($input['operation'] === 'case') {
        $case = app(PakCaseService::class)->create($actor, $input['case']);
        $result['case_id'] = $case->id;
    } elseif ($input['operation'] === 'master') {
        app(FunctionalMasterService::class)->save($actor, $input['type'], $input['master'], $input['master_id']);
    } else {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, json_encode([$input['source']], JSON_THROW_ON_ERROR));
        rewind($stream);
        $source = iterator_to_array((new SyntheticSkpSourceAdapter)->read($stream))[0];
        fclose($stream);
        $record = app(SkpFingerprintSerializer::class)->normalize($source, $input['person']);
        $evaluation = $input['operation'] === 'initial'
            ? app(PerformanceEvaluationService::class)->createInitial($actor, $record)
            : app(PerformanceEvaluationService::class)->correct($actor, $input['predecessor'], $record, 'Synthetic concurrency correction', $input['source_row']);
        $result['evaluation_id'] = $evaluation->id;
        $result['status'] = $evaluation->status->value;
    }
    $result['success'] = true;
} catch (Throwable $error) {
    $result['issue_code'] = $input['operation'] === 'conversion'
        ? ($error instanceof ValidationException ? 'VALIDATION_FAILED' : 'UNEXPECTED_FAILURE')
        : ($error instanceof ValidationException ? (array_values($error->errors())[0][0] ?? 'VALIDATION_FAILED')
            : ($error->issue_code ?? 'UNEXPECTED_FAILURE'));
}
if ($input['operation'] === 'conversion') {
    $result['transaction_level'] = DB::transactionLevel();
    $result['pdo_active'] = DB::connection()->getPdo()->inTransaction();
}
$result['diagnostics'] = ConcurrencyConnection::$diagnostics;
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
