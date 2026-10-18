<?php

namespace App\Domain\Pak\DTOs;

use Illuminate\Contracts\Support\Arrayable;

final readonly class DukReferenceData implements Arrayable
{
    public const FIELDS = ['id', 'person_id', 'reporting_period_id', 'import_batch_id', 'period_month', 'published_at', 'revision', 'name_at_period', 'nip_at_period', 'rank_name', 'grade_code', 'position_name', 'gender', 'placement_current'];

    public function __construct(private array $values) {}

    public function toArray(): array
    {
        return array_intersect_key($this->values, array_flip(self::FIELDS));
    }
}
