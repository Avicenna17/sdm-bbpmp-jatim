<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Services\ConversionRoundingPolicy;
use Brick\Math\BigRational;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TypeError;

class ConversionRoundingPolicyTest extends TestCase
{
    public static function values(): array
    {
        return [
            ['25/6', '4.167', '4,167'], ['125/6', '20.833', '20,833'],
            ['25', '25.000', '25'], ['75/4', '18.750', '18,75'],
            ['25/2', '12.500', '12,5'], ['20.8301', '20.830', '20,83'],
            ['1/2', '0.500', '0,5'], ['0', '0.000', '0'],
            ['100', '100.000', '100'], ['1234567.89', '1234567.890', '1234567,89'],
            ['18.7544', '18.754', '18,754'], ['18.7545', '18.755', '18,755'],
            ['18.7546', '18.755', '18,755'], ['0.0004', '0.000', '0'],
            ['0.0005', '0.001', '0,001'], ['0.000001', '0.000', '0'],
            ['0.9995', '1.000', '1'], ['15/64', '0.234', '0,234'],
        ];
    }

    #[DataProvider('values')]
    public function test_br_konv_004_output_is_deterministic_without_mutating_exact_value(string $exact, string $machine, string $human): void
    {
        $policy = new ConversionRoundingPolicy;
        $value = BigRational::of($exact);
        $before = [(string) $value->getNumerator(), (string) $value->getDenominator()];
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame($machine, $policy->decimal($value));
            $this->assertSame($human, $policy->display($value));
        }
        $this->assertSame($before, [(string) $value->getNumerator(), (string) $value->getDenominator()]);
    }

    public function test_profile_and_locale_independence_without_database(): void
    {
        $this->assertSame('PREVIEW_ONLY', ConversionRoundingPolicy::PROFILE);
        $this->assertSame(1, ConversionRoundingPolicy::VERSION);
        $this->assertSame(3, ConversionRoundingPolicy::SCALE);
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $policy = new ConversionRoundingPolicy;
        $locale = app()->getLocale();
        try {
            foreach (['en', 'id'] as $language) {
                app()->setLocale($language);
                $this->assertSame('1234567.500', $policy->decimal(BigRational::of('1234567.5')));
                $this->assertSame('1234567,5', $policy->display(BigRational::of('1234567.5')));
            }
        } finally {
            app()->setLocale($locale);
        }
        $this->assertSame([], $queries);
    }

    public function test_only_exact_rational_objects_are_accepted(): void
    {
        foreach (['decimal', 'display'] as $method) {
            foreach ([1.5, '1.5', 1, null] as $invalid) {
                try {
                    (new ConversionRoundingPolicy)->$method($invalid);
                    $this->fail('Expected BigRational type boundary.');
                } catch (TypeError) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }
}
