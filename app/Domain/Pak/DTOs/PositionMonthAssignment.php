<?php

namespace App\Domain\Pak\DTOs;

/** Scalar snapshot of the selected history; coverage references retain the continuity evidence. */
final readonly class PositionMonthAssignment
{
    public string $selection_reason;

    /** @param list<int> $coverage_history_ids */
    public function __construct(
        public string $month,
        public int $history_id,
        public int $person_id,
        public string $tmt_start,
        public ?string $tmt_end,
        public string $position_name,
        public string $position_type,
        public ?int $functional_position_id,
        public ?int $functional_level_id,
        public ?string $functional_level_code,
        public ?string $functional_level_name,
        public ?string $sk_number,
        public ?string $sk_date,
        public string $source,
        public string $source_ref,
        public int $verified_by,
        public string $verified_at,
        public array $coverage_history_ids,
    ) {
        $this->selection_reason = 'LATEST_APPLICABLE_TMT_AFTER_CONTINUITY_PROOF';
    }
}
