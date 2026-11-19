<?php

// Test-only process entrypoint. Credentials arrive on stdin and are never returned.
use App\Domain\Import\ImportService;
use App\Domain\Pak\DTOs\ConversionReplaceRequest;
use App\Domain\Pak\DTOs\ConversionReviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\Services\ConversionReviewService;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\EmployeeHistoryService;
use App\Domain\Pak\Services\FunctionalMasterService;
use App\Domain\Pak\Services\PakCaseService;
use App\Domain\Pak\Services\PerformanceEvaluationService;
use App\Domain\Pak\Services\SkpFingerprintSerializer;
use App\Domain\Pak\Services\SkpImportCommitService;
use App\Models\ImportBatch;
use App\Models\PakCase;
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
if (in_array($input['operation'], ['conversion', 'conversion_replace', 'conversion_review'], true)) {
    if (getenv('PAK_CONCURRENCY') !== '1' || getenv('PAK_DISPOSABLE_DB') !== 'pak_02a1_test'
        || (int) $input['db']['port'] !== 33318
        || DB::selectOne('SELECT DATABASE() AS db')->db !== 'pak_02a1_test'
        || ! str_starts_with(DB::selectOne('SELECT VERSION() AS v')->v, '10.5.11-MariaDB')) {
        throw new RuntimeException('Verified disposable MariaDB required.');
    }
}
if ($input['operation'] === 'conversion_review' && getenv('PAK_CONCURRENCY_PORT') !== (string) $input['db']['port']) {
    throw new RuntimeException('Explicit disposable concurrency port required.');
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
// C.7 only: observe the completed scalar SELECT, then pause after discover() returns,
// immediately before the owned transaction starts. Existing lock barriers are untouched.
if ($input['operation'] === 'conversion_review' && $input['barrier'] === 'after_mapping_discovery') {
    $mappingRead = false;
    DB::listen(function ($event) use (&$mappingRead) {
        $sql = strtolower($event->sql);
        if (str_starts_with($sql, 'select ') && str_contains($sql, 'functional_position_levels')
            && ! str_contains($sql, 'for update') && ! str_contains($sql, 'lock in share mode')
            && DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction()) {
            $mappingRead = true;
        }
    });
    DB::connection()->beforeStartingTransaction(function () use (&$once, &$mappingRead, $input, $connectionId, $signal, $wait) {
        if (! $once) {
            return;
        }
        $once = false;
        if (! $mappingRead || DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('INVALID_DISCOVERY_BARRIER');
        }
        file_put_contents($input['root'].'/'.$input['worker'].'.discovery', json_encode([
            'mapping_select_completed' => true, 'transaction_level' => DB::transactionLevel(),
            'pdo_active' => DB::connection()->getPdo()->inTransaction(),
            'pid' => getmypid(), 'connection_id' => $connectionId,
        ], JSON_THROW_ON_ERROR));
        $signal('held');
        $wait();
    });
}

$result = ['success' => false, 'issue_code' => null, 'batch_id' => $input['batch_id'] ?? null,
    'status' => null, 'evaluation_id' => null, 'connection_id' => $connectionId, 'pid' => getmypid()];
try {
    $actor = User::findOrFail($input['actor']);
    if (in_array($input['operation'], ['conversion', 'conversion_replace', 'conversion_review', 'correct', 'history', 'master', 'duk', 'initial', 'case_refresh'], true)) {
        $result['attempts'] = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$result) {
            $result['attempts']++;
        });
    }
    if ($input['operation'] === 'conversion') {
        $run = app(ConversionService::class)->saveInitial($actor, ConversionSaveRequest::from($input['request']));
        $result['run_id'] = $run->id;
        $result['status'] = $run->status->value;
    } elseif ($input['operation'] === 'conversion_replace') {
        $run = app(ConversionService::class)->replace($actor, ConversionReplaceRequest::from($input['request']));
        $result['run_id'] = $run->id;
        $result['status'] = $run->status->value;
    } elseif ($input['operation'] === 'conversion_review') {
        $run = app(ConversionReviewService::class)->assessReview($actor, ConversionReviewRequest::from($input['request']));
        $result['run_id'] = $run->id;
        $result['status'] = $run->status->value;
        $result['assessment_sequence'] = $run->review_assessment['assessment_sequence'];
    } elseif ($input['operation'] === 'commit') {
        $batch = app(SkpImportCommitService::class)->commit($actor, $input['batch_id']);
        $result += ['committed_by' => $batch->committed_by, 'committed_at' => $batch->committed_at->format('Y-m-d H:i:s'),
            'summary' => $batch->summary, 'rows' => $batch->rows()->orderBy('record_ordinal')->get()->map(fn ($row) => ['id' => $row->id, 'status' => $row->processing_status->value, 'evaluation_id' => $row->performance_evaluation_id])->all()];
        $result['status'] = $batch->status->value;
    } elseif ($input['operation'] === 'history') {
        $history = app(EmployeeHistoryService::class)->record($actor, Person::findOrFail($input['person']), $input['history'], true, $input['supersedes_id'] ?? null, $input['reason'] ?? null);
        $result['history_id'] = $history->id;
    } elseif ($input['operation'] === 'duk') {
        app(ImportService::class)->commit($actor, ImportBatch::findOrFail($input['batch_id']));
        $result['status'] = ImportBatch::findOrFail($input['batch_id'])->status;
    } elseif ($input['operation'] === 'case') {
        $case = app(PakCaseService::class)->create($actor, $input['case']);
        $result['case_id'] = $case->id;
    } elseif ($input['operation'] === 'case_refresh') {
        $case = app(PakCaseService::class)->refresh($actor, PakCase::findOrFail($input['case_id']),
            $input['case'], $input['expected_version'], $input['preview_hash'], $input['reason']);
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
    if (in_array($input['operation'], ['conversion_replace', 'conversion_review'], true)) {
        $result['failure_class'] = $error::class;
        // Expose only this fixed, non-sensitive reason so drift is distinguishable from stale hashes.
        if ($error instanceof ValidationException && $error->errors() === [
            'snapshot' => ['Dependency lock plan changed. Obtain a new preview.'],
        ]) {
            $result['lock_plan_errors'] = $error->errors();
        }
    }
    $result['validation_fields'] = $error instanceof ValidationException ? array_keys($error->errors()) : [];
    $result['issue_code'] = in_array($input['operation'], ['conversion', 'conversion_replace', 'conversion_review'], true)
        ? ($error instanceof ValidationException ? 'VALIDATION_FAILED' : 'UNEXPECTED_FAILURE')
        : ($error instanceof ValidationException ? (array_values($error->errors())[0][0] ?? 'VALIDATION_FAILED')
            : ($error->issue_code ?? 'UNEXPECTED_FAILURE'));
}
if (in_array($input['operation'], ['conversion', 'conversion_replace', 'conversion_review', 'correct', 'history', 'master', 'duk', 'initial', 'case_refresh'], true)) {
    $result['transaction_level'] = DB::transactionLevel();
    $result['pdo_active'] = DB::connection()->getPdo()->inTransaction();
}
if (in_array($input['operation'], ['conversion_replace', 'conversion_review'], true)) {
    $result['pending_callbacks'] = app('db.transactions')->getPendingTransactions()->count();
    $result['committed_callbacks'] = app('db.transactions')->getCommittedTransactions()->count();
}
$result['diagnostics'] = ConcurrencyConnection::$diagnostics;
// refresh() uses DB::transaction(), which also logs ordinary business rejection.
// Report its exact, clean case-precondition rejection through validation_fields;
// retain every query failure, chained exception, and unexpected transaction diagnostic.
if ($input['operation'] === 'case_refresh' && isset($error) && $error instanceof ValidationException
    && $error->errors() === ['case' => ['Draft berubah/terkunci atau person berbeda. Muat ulang.']]
    && $result['transaction_level'] === 0 && ! $result['pdo_active']
    && $result['diagnostics'] === [['transaction_failure' => [
        ['class' => ValidationException::class, 'code' => 0, 'sqlstate' => null, 'vendor' => null],
    ], 'level' => 0, 'validation_fields' => ['case']]]) {
    $result['diagnostics'] = [];
}
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
