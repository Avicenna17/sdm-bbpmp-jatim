<?php

namespace App\Domain\Pak\DTOs;

final readonly class ConversionRegimeEvidence
{
    private function __construct(public int $history_id, public ?string $effective_tmt, public ?string $regime, public ?string $issue_code) {}

    private static function nonblank(mixed $value): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && preg_match('/[^\p{Z}\s]/u', $value) === 1;
    }

    /** OD-13: only an OD-04 assignment with the exact existing verification invariant is proof. */
    public static function from(int $personId, PositionMonthAssignment $assignment, array $history): self
    {
        $complete = ($history['id'] ?? null) === $assignment->history_id
            && ($history['person_id'] ?? null) === $personId && $assignment->person_id === $personId
            && ($history['verified_by'] ?? null) !== null && ($history['verified_at'] ?? null) !== null
            && array_key_exists('superseded_at', $history) && $history['superseded_at'] === null
            && ($history['tmt_start'] ?? null) === $assignment->tmt_start
            && filled($history['tmt_start'] ?? null) && self::nonblank($history['sk_number'] ?? null) && self::nonblank($history['source_ref'] ?? null);
        if (! $complete) {
            return new self($assignment->history_id, null, null, 'REGIME_EVIDENCE_REQUIRES_REVIEW');
        }
        // BR-REGIME-001 / OD-12 approved boundary; sk_date never substitutes for effective TMT.
        $legacy = $assignment->tmt_start < '2023-01-01';

        return new self($assignment->history_id, $assignment->tmt_start, $legacy ? 'LEGACY' : 'CURRENT', $legacy ? 'LEGACY_REGIME_REQUIRES_REVIEW' : null);
    }
}
