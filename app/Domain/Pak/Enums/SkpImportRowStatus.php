<?php

namespace App\Domain\Pak\Enums;

enum SkpImportRowStatus: string
{
    case PENDING = 'PENDING';
    case READY = 'READY';
    case IMPORTED = 'IMPORTED';
    case DUPLICATE = 'DUPLICATE';
    case CONFLICT = 'CONFLICT';
    case IDENTITY_AMBIGUOUS = 'IDENTITY_AMBIGUOUS';
    case INVALID = 'INVALID';
    case FAILED = 'FAILED';
}
