<?php

namespace App\Domain\Pak\Exceptions;

use App\Domain\Pak\DTOs\SkpStoredSource;
use RuntimeException;

final class SkpSourceStorageException extends RuntimeException
{
    /** Recovery metadata is not a successful store result and must not be logged as business audit. */
    public function __construct(public readonly string $issue_code, public readonly ?SkpStoredSource $recovery_source = null)
    {
        parent::__construct($issue_code);
    }
}
