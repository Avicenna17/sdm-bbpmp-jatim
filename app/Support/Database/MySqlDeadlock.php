<?php

namespace App\Support\Database;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Throwable;

final class MySqlDeadlock
{
    public static function allows(Throwable $error): bool
    {
        while ($error instanceof DeadlockException && $error->getPrevious() !== null) {
            $error = $error->getPrevious();
        }

        return $error instanceof QueryException
            && ($error->errorInfo[0] ?? null) === '40001'
            && (int) ($error->errorInfo[1] ?? 0) === 1213;
    }
}
