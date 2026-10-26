<?php

namespace App\Domain\Pak\DTOs;

use InvalidArgumentException;

/** Internal resolved context; source selection does not resolve position applicability. */
final readonly class SkpPositionMonthContext
{
    /** @param array<string, int> $history_by_month Canonical YYYY-MM => persisted history ID. */
    public function __construct(public array $history_by_month)
    {
        foreach ($history_by_month as $month => $id) {
            if (! is_string($month) || ! preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/', $month) || ! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('INVALID_SOURCE_SELECTION_INPUT');
            }
        }
    }
}
