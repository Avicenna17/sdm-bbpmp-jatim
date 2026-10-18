<?php

namespace App\Domain\Pak\Enums;

enum PakCaseSource: string
{
    case ADMIN_MANUAL = 'ADMIN_MANUAL';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}
