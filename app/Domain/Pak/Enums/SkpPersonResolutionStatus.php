<?php

namespace App\Domain\Pak\Enums;

enum SkpPersonResolutionStatus: string
{
    case PENDING = 'PENDING';
    case MATCHED = 'MATCHED';
    case NOT_FOUND = 'NOT_FOUND';
    case AMBIGUOUS = 'AMBIGUOUS';
    case INVALID = 'INVALID';
}
