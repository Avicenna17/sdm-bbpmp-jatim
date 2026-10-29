<?php

namespace App\Domain\Pak\Services;

use App\Support\Database\MySqlDeadlock;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Throwable;

final class SkpCommitRetryPolicy
{
    public function allows(Throwable $error): bool
    {
        $error = $this->underlying($error);
        if (! $error instanceof QueryException) {
            return false;
        }
        [$state, $number, $message] = array_pad($error->errorInfo ?? [], 3, null);
        if ($this->allowsDeadlock($error)) {
            return true;
        }
        if ($state !== '23000') {
            return false;
        }
        if ((int) $number === 1062) {
            // PDO has no separate MySQL constraint field; combine SQLSTATE/vendor code with exact key.
            return preg_match('/for key [\'`](?:[^\'`]+\.)?pe_current_source_uq[\'`]\z/i', (string) $message) === 1;
        }

        return in_array((int) $number, [19, 2067], true)
            && $message === 'UNIQUE constraint failed: performance_evaluations.current_source_identity_fingerprint';
    }

    public function allowsDeadlock(Throwable $error): bool
    {
        return MySqlDeadlock::allows($error);
    }

    private function underlying(Throwable $error): Throwable
    {
        // Laravel wraps nested transaction deadlocks; retain exact driver-based detection.
        while ($error instanceof DeadlockException && $error->getPrevious() !== null) {
            $error = $error->getPrevious();
        }

        return $error;
    }
}
