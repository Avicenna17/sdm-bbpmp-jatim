<?php

namespace App\Domain\Pak\Enums;

enum PerformanceEvaluationStatus: string
{
    case CURRENT = 'CURRENT';
    case SUPERSEDED = 'SUPERSEDED';
}
