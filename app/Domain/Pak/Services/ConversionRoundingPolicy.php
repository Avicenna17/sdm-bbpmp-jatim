<?php

namespace App\Domain\Pak\Services;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

final class ConversionRoundingPolicy
{
    public const PROFILE = 'PREVIEW_ONLY';

    public const VERSION = 1;

    public const SCALE = 3;

    public function decimal(BigRational $value): string
    {
        // BR-KONV-004: presentation only; the authoritative rational stays exact.
        return (string) $value->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    public function display(BigRational $value): string
    {
        return str_replace('.', ',', rtrim(rtrim($this->decimal($value), '0'), '.'));
    }
}
