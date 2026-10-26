<?php

namespace App\Domain\Pak\DTOs;

use InvalidArgumentException;

/** Server-resolved month bounds, not date coverage or PakCase containment validation. */
final readonly class SkpRequestedYearPartition
{
    public function __construct(public int $year, public int $start_month, public int $end_month)
    {
        if ($year < 1 || $year > 9999 || $start_month < 1 || $end_month > 12 || $start_month > $end_month) {
            throw new InvalidArgumentException('INVALID_SOURCE_SELECTION_INPUT');
        }
    }

    /** @return list<string> */
    public function months(): array
    {
        return array_map(fn (int $month) => sprintf('%04d-%02d', $this->year, $month), range($this->start_month, $this->end_month));
    }
}
