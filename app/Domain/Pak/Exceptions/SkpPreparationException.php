<?php

namespace App\Domain\Pak\Exceptions;

use RuntimeException;

/** Safe caller-visible failure; never contains source bytes, SQL or exception traces. */
final class SkpPreparationException extends RuntimeException
{
    public function __construct(public readonly string $issue_code, public readonly ?int $batch_id = null)
    {
        parent::__construct($issue_code);
    }
}
