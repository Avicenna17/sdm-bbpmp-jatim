<?php

namespace Tests\Support\Pak;

use App\Domain\Pak\Services\SkpCommitRetryPolicy;
use Closure;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/** Driver diagnostics only; transaction/retry behavior remains Laravel's production implementation. */
class ConcurrencyConnection extends MySqlConnection
{
    public static array $diagnostics = [];

    public function transaction(Closure $callback, $attempts = 1)
    {
        try {
            return parent::transaction($callback, $attempts);
        } catch (\Throwable $error) {
            $chain = [];
            for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                $chain[] = ['class' => $cause::class, 'code' => $cause->getCode(),
                    'sqlstate' => $cause instanceof \PDOException ? ($cause->errorInfo[0] ?? null) : null,
                    'vendor' => $cause instanceof \PDOException ? ($cause->errorInfo[1] ?? null) : null];
            }
            self::$diagnostics[] = ['transaction_failure' => $chain, 'level' => $this->transactionLevel(),
                'validation_fields' => $error instanceof ValidationException ? array_keys($error->errors()) : []];
            throw $error;
        }
    }

    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        try {
            return parent::runQueryCallback($query, $bindings, $callback);
        } catch (QueryException $error) {
            $info = $error->errorInfo;
            $message = match ((int) ($info[1] ?? 0)) {
                1062 => preg_replace("/Duplicate entry '.*' for key /s", "Duplicate entry '<redacted>' for key ", $info[2]),
                1213 => 'Deadlock found when trying to get lock; try restarting transaction',
                default => 'Database error (details omitted)',
            };
            $policy = app(SkpCommitRetryPolicy::class);
            self::$diagnostics[] = ['sqlstate' => $info[0] ?? null, 'vendor' => $info[1] ?? null, 'message' => $message,
                'commit_retryable' => $policy->allows($error), 'correction_retryable' => $policy->allowsDeadlock($error)];
            throw $error;
        }
    }
}
