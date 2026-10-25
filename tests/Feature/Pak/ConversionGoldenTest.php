<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Calculators\ConversionCalculator;
use App\Domain\Pak\DTOs\ConversionInput;
use App\Domain\Pak\Services\ConversionRoundingPolicy;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConversionGoldenTest extends TestCase
{
    private function exact(BigRational $value, string $numerator, string $denominator = '1'): void
    {
        $this->assertSame($numerator, (string) $value->getNumerator());
        $this->assertSame($denominator, (string) $value->getDenominator());
    }

    public function test_nurmaulida_conversion_only_exact_lines_then_presentation(): void
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $calculator = new ConversionCalculator;
        $policy = new ConversionRoundingPolicy;
        $lines = [];
        foreach ([
            [2, '25', '6', '4.167', '4,167'],
            [12, '25', '1', '25.000', '25'],
            [12, '25', '1', '25.000', '25'],
            [10, '125', '6', '20.833', '20,833'],
        ] as [$months, $numerator, $denominator, $machine, $human]) {
            $line = $calculator->calculate(ConversionInput::from($months, '1', '25'));
            $this->exact($line, $numerator, $denominator);
            $this->assertSame($machine, $policy->decimal($line));
            $this->assertSame($human, $policy->display($line));
            $this->exact($line, $numerator, $denominator);
            $lines[] = $line;
        }
        $total = $calculator->total($lines);
        $this->exact($total, '75');
        $this->assertSame('75.000', $policy->decimal($total));
        $this->assertSame('75', $policy->display($total));
        $this->exact($total, '75');
        $this->assertSame([], $queries);
    }

    public function test_br_konv_004_exact_total_is_not_sum_of_presented_lines(): void
    {
        $calculator = new ConversionCalculator;
        $policy = new ConversionRoundingPolicy;
        $line = $calculator->calculate(ConversionInput::from(1, '1', '25'));
        $this->exact($line, '25', '12');
        $this->assertSame('2.083', $policy->decimal($line));
        $this->assertSame('2,083', $policy->display($line));
        $total = $calculator->total([$line, $line, $line]);
        $this->exact($total, '25', '4');
        $this->assertSame('6.250', $policy->decimal($total));
        $this->assertSame('6,25', $policy->display($total));
        // Counterexample only: this rounded-line sum is never passed to the calculator.
        $roundedLineSum = BigDecimal::of($policy->decimal($line))->multipliedBy(3);
        $this->assertSame('6.249', (string) $roundedLineSum);
        $this->assertSame('6,249', $policy->display($roundedLineSum->toBigRational()));
        $this->assertFalse($total->isEqualTo($roundedLineSum));
        $this->exact($line, '25', '12');
    }

    public function test_additional_exact_calculations_present_without_changing_values(): void
    {
        $calculator = new ConversionCalculator;
        $policy = new ConversionRoundingPolicy;
        foreach ([
            [12, '1.50', '12.5', '75', '4', '18.750', '18,75'],
            [6, '1', '37.5', '75', '4', '18.750', '18,75'],
            [3, '0.25', '3.75', '15', '64', '0.234', '0,234'],
        ] as [$months, $factor, $coefficient, $numerator, $denominator, $machine, $human]) {
            $line = $calculator->calculate(ConversionInput::from($months, $factor, $coefficient));
            $this->assertSame($machine, $policy->decimal($line));
            $this->assertSame($human, $policy->display($line));
            $this->exact($line, $numerator, $denominator);
        }
    }
}
