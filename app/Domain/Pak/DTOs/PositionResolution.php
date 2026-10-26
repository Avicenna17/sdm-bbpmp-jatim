<?php

namespace App\Domain\Pak\DTOs;

use InvalidArgumentException;

final readonly class PositionResolution
{
    public bool $is_complete;

    /** @var list<PositionSegment> */
    public array $segments;

    /**
     * @param  non-empty-list<string>  $months
     * @param  list<PositionMonthAssignment>  $assignments
     * @param  list<array{issue_code: string, month: string, interval_start: string, interval_end: string, history_ids: list<int>}>  $issues
     */
    public function __construct(public array $months, public array $assignments, public array $issues)
    {
        if ($months === [] || ! array_is_list($months) || ! array_is_list($assignments) || ! array_is_list($issues)) {
            throw new InvalidArgumentException('INVALID_POSITION_RESOLUTION');
        }
        $previous = null;
        foreach ($months as $month) {
            if (! is_string($month) || ! preg_match('/\A([0-9]{4})-(0[1-9]|1[0-2])\z/', $month, $parts)
                || ! checkdate((int) $parts[2], 1, (int) $parts[1])) {
                throw new InvalidArgumentException('INVALID_POSITION_RESOLUTION');
            }
            $ordinal = (int) $parts[1] * 12 + (int) $parts[2];
            if ($previous !== null && $ordinal !== $previous + 1) {
                throw new InvalidArgumentException('INVALID_POSITION_RESOLUTION');
            }
            $previous = $ordinal;
        }
        $indices = array_flip($months);
        $assigned = [];
        $previousIndex = -1;
        foreach ($assignments as $assignment) {
            if (! $assignment instanceof PositionMonthAssignment || ! isset($indices[$assignment->month])
                || $indices[$assignment->month] <= $previousIndex) {
                throw new InvalidArgumentException('INVALID_POSITION_RESOLUTION');
            }
            $assigned[$assignment->month] = true;
            $previousIndex = $indices[$assignment->month];
        }
        $affected = [];
        foreach ($issues as $issue) {
            if (! is_array($issue) || ! is_string($issue['month'] ?? null) || ! isset($indices[$issue['month']])
                || isset($assigned[$issue['month']]) || ! is_string($issue['issue_code'] ?? null) || $issue['issue_code'] === '') {
                throw new InvalidArgumentException('INVALID_POSITION_RESOLUTION');
            }
            // Multiple diagnostic intervals may explain the same unresolved month.
            $affected[$issue['month']] = true;
        }
        if (count($assigned) + count($affected) !== count($months)) {
            throw new InvalidArgumentException('INVALID_POSITION_RESOLUTION');
        }
        $this->is_complete = $issues === [] && count($assignments) === count($months);
        $groups = [];
        $group = [];
        $previous = null;
        foreach ($assignments as $assignment) {
            $ordinal = (int) substr($assignment->month, 0, 4) * 12 + (int) substr($assignment->month, 5, 2);
            if ($group !== [] && ($ordinal !== $previous + 1 || $assignment->history_id !== $group[0]->history_id)) {
                $groups[] = new PositionSegment($group);
                $group = [];
            }
            $group[] = $assignment;
            $previous = $ordinal;
        }
        if ($group !== []) {
            $groups[] = new PositionSegment($group);
        }
        $this->segments = $groups;
    }

    /** Unresolved months are deliberately absent, even for an incomplete overall result. */
    public function positionContext(): SkpPositionMonthContext
    {
        $mapping = [];
        foreach ($this->assignments as $assignment) {
            $mapping[$assignment->month] = $assignment->history_id;
        }

        return new SkpPositionMonthContext($mapping);
    }
}
