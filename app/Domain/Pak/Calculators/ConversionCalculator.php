<?php

namespace App\Domain\Pak\Calculators;

use App\Domain\Pak\DTOs\ConversionInput;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Illuminate\Validation\ValidationException;

final class ConversionCalculator
{
    public function calculate(ConversionInput $input): BigRational
    {
        // BR-KONV-001/002/003: resolved months, exact operands, no presentation rounding.
        return BigRational::ofFraction($input->monthCount, 12)
            ->multipliedBy(BigDecimal::of($input->predicateFactor)->toBigRational())
            ->multipliedBy(BigDecimal::of($input->annualCoefficient)->toBigRational())
            ->simplified();
    }

    public function total(iterable $exactLines): BigRational
    {
        $total = BigRational::zero();
        foreach ($exactLines as $line) {
            if (! $line instanceof BigRational || $line->isNegative()) {
                throw ValidationException::withMessages(['exact_lines' => 'Setiap nilai wajib BigRational nonnegatif.']);
            }
            $total = $total->plus($line);
        }

        // Empty sum is mathematical zero; conversion-run eligibility belongs to 02C.
        return $total->simplified();
    }
}
