<?php

namespace App\Domain\Pak\Rules;

use Illuminate\Validation\ValidationException;

final class ConversionRuleValues
{
    public static function predicateCode(mixed $value): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw ValidationException::withMessages(['predicate_code' => 'Kode wajib berupa string UTF-8.']);
        }
        $value = preg_replace('/\A[\p{Z}\x{0009}-\x{000D}]+|[\p{Z}\x{0009}-\x{000D}]+\z/u', '', $value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > 50) {
            throw ValidationException::withMessages(['predicate_code' => 'Kode wajib nonblank, maksimum 50 karakter.']);
        }

        return $value;
    }

    public static function predicateFactor(mixed $value): string
    {
        return self::decimal($value, 8, 4, 'predicate_factor');
    }

    public static function annualCoefficient(mixed $value): string
    {
        return self::decimal($value, 15, 6, 'annual_coefficient');
    }

    private static function decimal(mixed $value, int $precision, int $scale, string $field): string
    {
        if (! is_string($value) || ! preg_match('/\A([0-9]+)(?:\.([0-9]+))?\z/', $value, $parts)) {
            throw ValidationException::withMessages([$field => 'Nilai wajib berupa string desimal positif tanpa eksponen atau whitespace.']);
        }
        $integer = ltrim($parts[1], '0');
        $fraction = $parts[2] ?? '';
        if (strlen($integer) > $precision - $scale || strlen($fraction) > $scale
            || ($integer === '' && trim($fraction, '0') === '')) {
            throw ValidationException::withMessages([$field => 'Nilai harus positif dan tepat dalam precision/scale schema; pembulatan tidak diizinkan.']);
        }

        return ($integer === '' ? '0' : $integer).'.'.str_pad($fraction, $scale, '0');
    }
}
