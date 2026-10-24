<?php

namespace App\Domain\Pak\Enums;

enum PerformanceEvaluationType: string
{
    case Q1 = 'Q1';
    case Q2 = 'Q2';
    case Q3 = 'Q3';
    case Q4 = 'Q4';
    case ANNUAL = 'ANNUAL';
    case CUSTOM_PERIOD = 'CUSTOM_PERIOD';
}
