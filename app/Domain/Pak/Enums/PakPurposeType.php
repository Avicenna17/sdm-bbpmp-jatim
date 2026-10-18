<?php

namespace App\Domain\Pak\Enums;

enum PakPurposeType: string
{
    case ROUTINE = 'ROUTINE';
    case KP = 'KP';
    case KJ = 'KJ';
    case KP_KJ = 'KP_KJ';

    public static function options(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}
