<?php

namespace App\Domain\Pak\Enums;

enum SkpImportBatchStatus: string
{
    case PARSING = 'PARSING';
    case INVALID = 'INVALID';
    case READY = 'READY';
    case COMMITTED = 'COMMITTED';
    case FAILED = 'FAILED';
}
