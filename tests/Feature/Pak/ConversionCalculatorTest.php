<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Calculators\ConversionCalculator;
use App\Domain\Pak\DTOs\ConversionInput;
use App\Domain\Pak\DTOs\ResolvedCoefficientRule;
use App\Domain\Pak\DTOs\ResolvedPredicateRule;
use App\Models\PakRuleSet;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Error;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class ConversionCalculatorTest extends TestCase
{
    // No RefreshDatabase, migrations, fixtures, or container-resolved calculator.
    private function exact(BigRational $value, string $numerator, string $denominator = '1'): void
    {
        $this->assertSame($numerator, (string) $value->getNumerator());
        $this->assertSame($denominator, (string) $value->getDenominator());
    }

    public static function numericCases(): array
    {
        return [
            [2, '1', '25', '25', '6'],
            [10, '1', '25', '125', '6'],
            [12, '1.50', '12.5', '75', '4'],
            [12, '1', '25', '25', '1'],
            [6, '1', '37.5', '75', '4'],
            [3, '0.25', '3.75', '15', '64'],
            [24, '1', '25', '50', '1'],
            [1, '0.0001', '0.000001', '1', '120000000000'],
            [12, '1', '999999999.999999', '999999999999999', '1000000'],
            [PHP_INT_MAX, '1', '12', (string) PHP_INT_MAX, '1'],
        ];
    }

    #[DataProvider('numericCases')]
    public function test_exact_simplified_numeric_fixtures(int $months, string $factor, string $coefficient, string $numerator, string $denominator): void
    {
        $calculator = new ConversionCalculator;
        $input = ConversionInput::from($months, $factor, $coefficient);
        $this->exact($calculator->calculate($input), $numerator, $denominator);
        $this->exact($calculator->calculate($input), $numerator, $denominator);
    }

    public function test_nurmaulida_exact_lines_and_total_without_accumulation_or_presentation(): void
    {
        $calculator = new ConversionCalculator;
        $lines = array_map(fn (int $months) => $calculator->calculate(ConversionInput::from($months, '1', '25')), [2, 12, 12, 10]);
        foreach ([['25', '6'], ['25', '1'], ['25', '1'], ['125', '6']] as $i => [$numerator, $denominator]) {
            $this->exact($lines[$i], $numerator, $denominator);
        }
        $this->exact($calculator->total($lines), '75');
    }

    public function test_total_accepts_generator_zero_and_unreduced_exact_lines(): void
    {
        $calculator = new ConversionCalculator;
        $lines = (function () {
            yield BigRational::zero();
            yield BigRational::ofFraction(2, 6);
            yield BigRational::ofFraction(1, 3);
            yield BigRational::ofFraction(3, 9);
        })();
        $this->exact($calculator->total($lines), '1');
        $this->exact($calculator->total([]), '0');
        $this->exact($calculator->total([BigRational::zero()]), '0');
        $this->exact($calculator->total([BigRational::ofFraction(25, 12), BigRational::ofFraction(25, 12), BigRational::ofFraction(25, 12)]), '25', '4');
    }

    public static function invalidMonths(): array
    {
        return [['12'], [12.0], [true], [false], [null], [0], [-1], [[]]];
    }

    #[DataProvider('invalidMonths')]
    public function test_month_count_requires_positive_native_integer(mixed $months): void
    {
        $this->rejected(fn () => ConversionInput::from($months, '1', '25'), 'month_count');
    }

    public static function invalidDecimals(): array
    {
        $cases = [];
        foreach (['predicate_factor', 'annual_coefficient'] as $field) {
            foreach ([1, 1.0, true, null, '', '0', '0.0000', '-1', '1e0', '1,50', ' 1', '1 ', '1 .5', 'NaN', 'Infinity'] as $value) {
                $cases[] = [$field, $value];
            }
        }

        return [...$cases, ['predicate_factor', '10000'], ['predicate_factor', '1.00001'],
            ['annual_coefficient', '1000000000'], ['annual_coefficient', '1.0000001']];
    }

    #[DataProvider('invalidDecimals')]
    public function test_decimal_contract_reuses_rule_validation(string $field, mixed $value): void
    {
        $this->rejected(fn () => ConversionInput::from(12, $field === 'predicate_factor' ? $value : '1',
            $field === 'annual_coefficient' ? $value : '25'), $field);
    }

    public function test_total_rejects_non_rational_and_negative_values(): void
    {
        foreach ([1.5, '1.5', 1, null, true, new PakRuleSet, BigDecimal::of('1.5'), BigRational::ofFraction(-1, 3)] as $invalid) {
            $this->rejected(fn () => (new ConversionCalculator)->total([BigRational::of(1), $invalid]), 'exact_lines');
        }
    }

    private function rejected(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected safe validation failure.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    public function test_input_is_canonical_immutable_and_has_private_constructor(): void
    {
        $input = ConversionInput::from(12, '01.50', '012.5');
        $this->assertSame(12, $input->monthCount);
        $this->assertSame('1.5000', $input->predicateFactor);
        $this->assertSame('12.500000', $input->annualCoefficient);
        $this->assertTrue((new ReflectionClass(ConversionInput::class))->getConstructor()->isPrivate());
        foreach (['monthCount' => 24, 'predicateFactor' => '2', 'annualCoefficient' => '50'] as $field => $value) {
            try {
                $input->$field = $value;
                $this->fail('Input must be immutable.');
            } catch (Error $error) {
                $this->assertStringContainsString('readonly', $error->getMessage());
            }
        }
    }

    public function test_calculator_is_locale_independent_and_uses_no_database(): void
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $predicate = new ResolvedPredicateRule(1, 2, 'SYNTHETIC', 'Synthetic', '1.5000');
        $coefficient = new ResolvedCoefficientRule(1, 3, 4, '12.500000');
        $calculator = new ConversionCalculator;
        $originalLocale = setlocale(LC_NUMERIC, 0);
        $appLocale = app()->getLocale();
        try {
            foreach (['en', 'id'] as $locale) {
                app()->setLocale($locale);
                setlocale(LC_NUMERIC, $locale === 'en' ? 'C' : 'Indonesian_Indonesia.1252', 'id_ID.UTF-8', 'C');
                $input = ConversionInput::from(12, $predicate->predicateFactor, $coefficient->annualCoefficient);
                $line = $calculator->calculate($input);
                $this->exact($line, '75', '4');
                $this->exact($calculator->total([$line, $line]), '75', '2');
            }
            $this->rejected(fn () => ConversionInput::from(12, '1,5', '25'), 'predicate_factor');
        } finally {
            setlocale(LC_NUMERIC, $originalLocale);
            app()->setLocale($appLocale);
        }
        $this->assertSame([], $queries);
    }
}
