<?php

namespace App\Domain\Pak\DTOs;

use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;

final readonly class NormalizedSkpRecord
{
    private function __construct(
        public int $person_id,
        public PerformanceSourceKind $source_kind,
        public int $performance_year,
        public PerformanceEvaluationType $evaluation_type,
        public string $source_reference,
        public string $source_record_identifier,
        public string $predicate_code,
        public string $predicate_label_original,
        public string $coverage_start,
        public string $coverage_end,
        public ?string $source_date,
        public ?string $source_position_name,
        public ?string $source_functional_level_label,
        public ?string $source_position_tmt,
        public ?string $source_sk_number,
        public ?string $source_sk_date,
        public SkpSourceIdentityEvidence $stable_identifier_evidence,
    ) {}

    /**
     * @internal Normalization boundary factory, not an application input API.
     * Payload/fingerprint boundaries reject non-canonical values rather than repair
     * this record. Raw source must go through the serializer's normalize() boundary.
     */
    public static function fromCanonicalValues(array $values): self
    {
        return new self(...$values);
    }
}
