<?php

namespace App\Domain\Pak\DTOs;

/**
 * Structural assertion supplied by a trusted source producer, validated at normalization.
 * The strategy key identifies the producer's stable-ID contract; the component reference
 * identifies its source field, not an Excel occurrence/row used to fabricate an ID.
 * Actual source verification remains the responsibility of the future adapter.
 */
final readonly class SkpSourceIdentityEvidence
{
    public function __construct(
        public string $strategy_key,
        public string $component_reference,
        public string $component_value,
    ) {}
}
