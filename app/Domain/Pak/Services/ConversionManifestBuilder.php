<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\DTOs\ConversionDependencyGraph;
use App\Domain\Pak\DTOs\ConversionPreviewLine;
use Brick\Math\BigRational;
use InvalidArgumentException;

final class ConversionManifestBuilder
{
    /** No object serialization, floats, volatile execution metadata, or self-hashes. */
    public static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::canonical(...), $value);
        }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }
        throw new InvalidArgumentException('Conversion manifest requires scalar/array values.');
    }

    public static function hash(array $values): string
    {
        return hash('sha256', json_encode(self::canonical($values), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function rational(?BigRational $value): ?array
    {
        return $value === null ? null : ['numerator' => (string) $value->getNumerator(), 'denominator' => (string) $value->getDenominator()];
    }

    /** Snapshot adds immutable outputs to semantic dependencies; excludes presentation and execution/version increment. */
    public function snapshot(ConversionDependencyGraph $graph, array $lines, ?BigRational $total, array $issues): string
    {
        return self::hash(['dependencies' => $graph->groups, 'lines' => array_map(fn (ConversionPreviewLine $line) => [
            'months' => $line->months, 'evaluation_id' => $line->source?->evaluation_id, 'history_id' => $line->position?->history_id,
            'predicate_rule_id' => $line->predicate?->childRuleId, 'coefficient_rule_id' => $line->coefficient?->childRuleId,
            'predicate_factor' => $line->predicate?->predicateFactor, 'annual_coefficient' => $line->coefficient?->annualCoefficient,
            'exact' => self::rational($line->exact), 'issues' => $line->issues,
        ], $lines), 'total' => self::rational($total), 'issues' => $issues]);
    }
}
