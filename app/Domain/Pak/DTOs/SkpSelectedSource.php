<?php

namespace App\Domain\Pak\DTOs;

use App\Domain\Pak\Enums\PerformanceEvaluationType;

final readonly class SkpSelectedSource
{
    /** @param list<string> $applied_months */
    public function __construct(
        public int $evaluation_id,
        public int $person_id,
        public int $performance_year,
        public PerformanceEvaluationType $evaluation_type,
        public string $predicate_code,
        public string $source_identity_fingerprint,
        public string $content_fingerprint,
        public string $coverage_start,
        public string $coverage_end,
        public string $source_reference,
        public ?string $source_date,
        public array $applied_months,
        public string $selection_reason,
        public ?int $history_id,
        public ?string $history_proof_method,
    ) {}
}
