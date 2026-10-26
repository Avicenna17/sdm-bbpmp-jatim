<?php

namespace App\Domain\Pak\Rules;

use Brick\Math\BigRational;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use JsonException;

final class ConversionPersistenceValues
{
    public const SNAPSHOT_SCHEMA_VERSION = 1;

    public const CALCULATION_CONTRACT_VERSION = 1;

    public static function reject(string $field): never
    {
        throw ValidationException::withMessages([$field => 'INVALID_CONVERSION_PERSISTENCE']);
    }

    public static function rational(mixed $numerator, mixed $denominator, string $field): void
    {
        if ($numerator === null && $denominator === null) {
            return;
        }
        if (! is_string($numerator) || ! preg_match('/\A(?:0|[1-9][0-9]*)\z/', $numerator)
            || ! is_string($denominator) || ! preg_match('/\A[1-9][0-9]*\z/', $denominator)) {
            self::reject($field);
        }
        $value = BigRational::ofFraction($numerator, $denominator)->simplified();
        if ((string) $value->getNumerator() !== $numerator || (string) $value->getDenominator() !== $denominator) {
            self::reject($field);
        }
    }

    /** Accept only explicit scalar/array snapshot input, never auto-serialize models or arbitrary objects. */
    public static function json(mixed $value, string $field, bool $nullable = false): ?string
    {
        if ($nullable && $value === null) {
            return null;
        }
        if (! is_array($value)) {
            self::reject($field);
        }
        $check = function (array $items) use (&$check, $field): void {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $check($item);
                } elseif (is_object($item) || is_resource($item)) {
                    self::reject($field);
                }
            }
        };
        $check($value);

        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            self::reject($field);
        }
    }

    public static function date(string $value, string $field): DateTimeImmutable
    {
        if (! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            self::reject($field);
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
