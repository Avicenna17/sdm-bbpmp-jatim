<?php

namespace App\Domain\Pak\Exceptions;

use RuntimeException;

final class SkpCommitException extends RuntimeException
{
    public function __construct(public readonly string $issue_code, public readonly int $batch_id)
    {
        parent::__construct($issue_code);
    }
}
