<?php

namespace App\Domain\Pak\Enums;

enum ConversionRunStatus: string
{
    case PREVIEW = 'PREVIEW';
    case REQUIRES_REVIEW = 'REQUIRES_REVIEW';
    case SUPERSEDED = 'SUPERSEDED';
}
