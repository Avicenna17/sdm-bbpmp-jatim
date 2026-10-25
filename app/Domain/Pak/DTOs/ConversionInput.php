<?php

namespace App\Domain\Pak\DTOs;

use App\Domain\Pak\Rules\ConversionRuleValues;
use Illuminate\Validation\ValidationException;

final readonly class ConversionInput
{
    private function __construct(
        public int $monthCount,
        public string $predicateFactor,
        public string $annualCoefficient,
    ) {}

    public static function from(mixed $monthCount, mixed $predicateFactor, mixed $annualCoefficient): self
    {
        if (! is_int($monthCount) || $monthCount <= 0) {
            throw ValidationException::withMessages(['month_count' => 'Jumlah bulan wajib integer positif.']);
        }

        return new self($monthCount, ConversionRuleValues::predicateFactor($predicateFactor),
            ConversionRuleValues::annualCoefficient($annualCoefficient));
    }
}
