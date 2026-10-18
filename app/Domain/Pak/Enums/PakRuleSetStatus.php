<?php

namespace App\Domain\Pak\Enums;

enum PakRuleSetStatus: string
{
    case DRAFT = 'DRAFT';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}
