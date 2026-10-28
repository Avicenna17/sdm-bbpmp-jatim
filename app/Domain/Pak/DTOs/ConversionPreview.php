<?php

namespace App\Domain\Pak\DTOs;

use Brick\Math\BigRational;

final readonly class ConversionPreview
{
    /** @param list<ConversionPreviewLine> $lines @param list<array{issue_code: string, month?: string, months?: list<string>, reason?: string, history_id?: int, history_ids?: list<int>, interval_start?: string, interval_end?: string, requested_start?: string, requested_end?: string, valid_from?: ?string, valid_until?: ?string, rule_set_id?: int, rule_set_version?: string}> $issues */
    public function __construct(
        public ConversionPreviewRequest $request,
        public int $expected_case_version,
        public MonthlyCoverage $coverage,
        public ?PositionResolution $positions,
        public array $lines,
        public ?BigRational $exact_total,
        public array $issues,
        public ConversionDependencyGraph $dependencies,
        public string $dependency_fingerprint,
        public string $snapshot_fingerprint,
        public ?string $machine_total,
        public ?string $human_total,
    ) {}
}
