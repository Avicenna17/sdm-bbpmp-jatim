<?php

namespace App\Domain\Pak\DTOs;

use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;

/** Source occurrence, including incomplete values; never a fingerprint-eligible record itself. */
final readonly class SkpSourceRowData
{
    public function __construct(
        public ?string $nip = null,
        public ?PerformanceSourceKind $source_kind = null,
        public ?int $performance_year = null,
        public ?PerformanceEvaluationType $evaluation_type = null,
        public ?string $source_reference = null,
        public ?string $source_record_identifier = null,
        public ?string $predicate_code = null,
        public ?string $predicate_label_original = null,
        public ?string $coverage_start = null,
        public ?string $coverage_end = null,
        public ?string $source_date = null,
        public ?string $source_position_name = null,
        public ?string $source_functional_level_label = null,
        public ?string $source_position_tmt = null,
        public ?string $source_sk_number = null,
        public ?string $source_sk_date = null,
        public ?SkpSourceIdentityEvidence $stable_identifier_evidence = null,
        public array $source_locator = [],
        public array $raw_provenance = [],
        public array $validation_issues = [],
    ) {}
}
