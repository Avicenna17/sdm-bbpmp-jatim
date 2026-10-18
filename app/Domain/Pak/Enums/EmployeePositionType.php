<?php

namespace App\Domain\Pak\Enums;

enum EmployeePositionType: string
{
    case JF = 'JF';
    case JA = 'JA';
    case PELAKSANA = 'PELAKSANA';
    case OTHER = 'OTHER';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}
