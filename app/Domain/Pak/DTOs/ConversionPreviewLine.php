<?php

namespace App\Domain\Pak\DTOs;

use Brick\Math\BigRational;

final readonly class ConversionPreviewLine
{
    /** @param list<string> $months @param list<array{issue_code: string, month?: string, months?: list<string>, reason?: string, history_id?: int, history_ids?: list<int>, interval_start?: string, interval_end?: string, requested_start?: string, requested_end?: string, valid_from?: ?string, valid_until?: ?string, rule_set_id?: int, rule_set_version?: string}> $issues */
    public function __construct(
        public array $months,
        public ?SkpSelectedSource $source,
        public ?PositionMonthAssignment $position,
        public ?ConversionRegimeEvidence $regime,
        public ?ResolvedPredicateRule $predicate,
        public ?ResolvedCoefficientRule $coefficient,
        public ?BigRational $exact,
        public array $issues,
        public ?string $machine,
        public ?string $human,
    ) {}
}
