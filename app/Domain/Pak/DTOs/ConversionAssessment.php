<?php

declare(strict_types=1);

namespace App\Domain\Pak\DTOs;

final readonly class ConversionAssessment
{
    /** @param list<string> $changed_groups @param list<string> $current_issue_codes */
    public function __construct(
        public int $pak_case_id,
        public int $run_id,
        public int $revision_number,
        public string $observed_run_status,
        public bool $is_current_active,
        public int $observed_case_lock_version,
        public int $assessment_sequence,
        public string $comparison,
        public string $saved_dependency_fingerprint,
        public string $saved_snapshot_fingerprint,
        public ?string $current_dependency_fingerprint,
        public ?string $current_snapshot_fingerprint,
        public array $changed_groups,
        public array $current_issue_codes,
        public ?string $failure_code,
        public string $assessment_fingerprint,
    ) {}
}
