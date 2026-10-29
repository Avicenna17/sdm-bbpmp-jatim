<?php

namespace App\Domain\Pak\Rules;

use App\Domain\Pak\DTOs\ConversionPreview;
use App\Domain\Pak\Enums\ConversionRunStatus;
use Illuminate\Validation\ValidationException;

final class ConversionSaveability
{
    public const REVIEW_CODES = [
        'PARTIAL_MONTH_REQUIRES_REVIEW', 'SOURCE_NOT_FOUND', 'AMBIGUOUS_SOURCE_SELECTION', 'SOURCE_POSITION_LINK_REQUIRES_REVIEW',
        'MISSING_POSITION_HISTORY', 'AMBIGUOUS_POSITION_HISTORY', 'RULE_VALIDITY_REQUIRES_REVIEW', 'LEGACY_REGIME_REQUIRES_REVIEW',
        'REGIME_EVIDENCE_REQUIRES_REVIEW', 'PREDICATE_RULE_NOT_FOUND', 'COEFFICIENT_RULE_NOT_FOUND', 'MISSING_FUNCTIONAL_LEVEL',
    ];

    public function statusFor(ConversionPreview $result): ConversionRunStatus
    {
        foreach (array_merge($result->issues, ...array_map(fn ($line) => $line->issues, $result->lines)) as $issue) {
            if (! in_array($issue['issue_code'] ?? null, self::REVIEW_CODES, true)) {
                $this->reject();
            }
        }
        if ($result->issues === []) {
            if (! $result->coverage->is_complete || $result->exact_total === null || $result->lines === []) {
                $this->reject();
            }
            $months = [];
            foreach ($result->lines as $line) {
                if ($line->exact === null || $line->issues !== [] || $line->source === null || $line->position === null || $line->predicate === null || $line->coefficient === null) {
                    $this->reject();
                }
                array_push($months, ...$line->months);
            }
            if ($months !== $result->coverage->months) {
                $this->reject();
            }

            return ConversionRunStatus::PREVIEW;
        }
        if ($result->exact_total !== null || (! $result->coverage->is_complete && $result->lines !== [])) {
            $this->reject();
        }
        $months = [];
        foreach ($result->lines as $line) {
            array_push($months, ...$line->months);
            if (($line->exact === null) !== ($line->issues !== [])) {
                $this->reject();
            }
        }

        $codes = array_column($result->issues, 'issue_code');
        if ($result->coverage->is_complete === in_array('PARTIAL_MONTH_REQUIRES_REVIEW', $codes, true)) {
            $this->reject();
        }
        if ($result->coverage->is_complete && $months !== $result->coverage->months
            && ! ($months === [] && in_array('RULE_VALIDITY_REQUIRES_REVIEW', $codes, true))) {
            $this->reject();
        }

        return ConversionRunStatus::REQUIRES_REVIEW;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['conversion' => 'Conversion cannot be saved. Review dependencies and obtain a new preview.']);
    }
}
