<?php

namespace App\Domain\Pak\Exceptions;

use RuntimeException;

final class ConversionRuleException extends RuntimeException
{
    public function __construct(public readonly string $issue_code)
    {
        parent::__construct($issue_code);
    }
}
