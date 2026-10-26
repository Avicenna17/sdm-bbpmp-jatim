<?php

namespace App\Domain\Pak\DTOs;

use InvalidArgumentException;

/** Derived grouping only; monthly assignments remain canonical. */
final readonly class PositionSegment
{
    public string $start_month;

    public string $end_month;

    public int $month_count;

    public array $months;

    public int $history_id;

    /** @param non-empty-list<PositionMonthAssignment> $assignments */
    public function __construct(public array $assignments)
    {
        if ($assignments === [] || ! array_is_list($assignments)) {
            throw new InvalidArgumentException('INVALID_POSITION_SEGMENT');
        }
        $previous = null;
        $history = null;
        $person = null;
        foreach ($assignments as $assignment) {
            if (! $assignment instanceof PositionMonthAssignment) {
                throw new InvalidArgumentException('INVALID_POSITION_SEGMENT');
            }
            $ordinal = (int) substr($assignment->month, 0, 4) * 12 + (int) substr($assignment->month, 5, 2);
            if ($previous !== null && ($ordinal !== $previous + 1 || $history !== $assignment->history_id || $person !== $assignment->person_id)) {
                throw new InvalidArgumentException('INVALID_POSITION_SEGMENT');
            }
            $previous = $ordinal;
            $history = $assignment->history_id;
            $person = $assignment->person_id;
        }
        $this->months = array_map(fn ($a) => $a->month, $assignments);
        $this->start_month = $this->months[0];
        $this->end_month = $this->months[array_key_last($this->months)];
        $this->month_count = count($assignments);
        $this->history_id = $history;
    }
}
