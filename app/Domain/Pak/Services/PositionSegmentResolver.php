<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\DTOs\PositionMonthAssignment;
use App\Domain\Pak\DTOs\PositionResolution;
use App\Models\EmployeePositionHistory;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PositionSegmentResolver
{
    /** @param non-empty-list<string> $months Ordered canonical months from completed monthly coverage. */
    public function resolve(int $personId, array $months): PositionResolution
    {
        if ($personId < 1 || $months === [] || ! array_is_list($months)) {
            throw new InvalidArgumentException('INVALID_POSITION_RESOLUTION_INPUT');
        }
        $previous = null;
        foreach ($months as $month) {
            if (! is_string($month) || ! preg_match('/\A([0-9]{4})-(0[1-9]|1[0-2])\z/', $month, $parts)
                || ! checkdate((int) $parts[2], 1, (int) $parts[1])) {
                throw new InvalidArgumentException('INVALID_POSITION_RESOLUTION_INPUT');
            }
            $ordinal = (int) $parts[1] * 12 + (int) $parts[2];
            if ($previous !== null && $ordinal !== $previous + 1) {
                throw new InvalidArgumentException('INVALID_POSITION_RESOLUTION_INPUT');
            }
            $previous = $ordinal;
        }
        $last = $this->monthStart($months[array_key_last($months)])->format('Y-m-t');
        $histories = EmployeePositionHistory::query()->with('functionalLevel')
            ->where('person_id', $personId)->whereNotNull('verified_by')->whereNotNull('verified_at')->whereNull('superseded_at')
            ->whereDate('tmt_start', '<=', $last)
            ->where(fn ($q) => $q->whereNull('tmt_end')->orWhereDate('tmt_end', '>=', $months[0].'-01'))
            ->orderBy('tmt_start')->orderBy('id')->get();

        $assignments = [];
        $issues = [];
        foreach ($months as $month) {
            $start = $month.'-01';
            $end = $this->monthStart($month)->format('Y-m-t');
            $days = (int) substr($end, 8, 2);
            $intervals = [];
            $boundaries = [1, $days + 1];
            foreach ($histories as $history) {
                $historyStart = $history->tmt_start->format('Y-m-d');
                $historyEnd = $history->tmt_end?->format('Y-m-d');
                if ($historyStart > $end || ($historyEnd !== null && $historyEnd < $start)) {
                    continue;
                }
                $first = (int) substr(max($start, $historyStart), 8, 2);
                $lastDay = (int) substr(min($end, $historyEnd ?? $end), 8, 2);
                $intervals[] = ['first' => $first, 'last' => $lastDay, 'history' => $history];
                $boundaries[] = $first;
                $boundaries[] = $lastDay + 1;
            }
            $boundaries = array_values(array_unique($boundaries));
            sort($boundaries, SORT_NUMERIC);
            $monthIssues = [];
            // OD-04: sweep inclusive interval boundaries BEFORE selecting a whole-month winner.
            for ($i = 0; $i < count($boundaries) - 1; $i++) {
                $first = $boundaries[$i];
                $lastDay = $boundaries[$i + 1] - 1;
                $active = array_values(array_filter($intervals, fn ($range) => $range['first'] <= $first && $range['last'] >= $first));
                if (count($active) === 1) {
                    continue;
                }
                $ids = array_map(fn ($range) => (int) $range['history']->id, $active);
                // IDs order diagnostics only, never source/position priority.
                sort($ids, SORT_NUMERIC);
                $monthIssues[] = ['issue_code' => $active === [] ? 'MISSING_POSITION_HISTORY' : 'AMBIGUOUS_POSITION_HISTORY',
                    'month' => $month, 'interval_start' => sprintf('%s-%02d', $month, $first),
                    'interval_end' => sprintf('%s-%02d', $month, $lastDay), 'history_ids' => $ids];
            }
            if ($monthIssues !== []) {
                array_push($issues, ...$monthIssues);

                continue;
            }
            // BR-JAB-001: no ties can survive the continuity/no-overlap proof.
            $selected = null;
            $coverageIds = [];
            foreach ($intervals as $interval) {
                $history = $interval['history'];
                $coverageIds[] = (int) $history->id;
                if ($selected === null || $history->tmt_start->gt($selected->tmt_start)) {
                    $selected = $history;
                }
            }
            sort($coverageIds, SORT_NUMERIC);
            $assignments[] = new PositionMonthAssignment(
                month: $month, history_id: $selected->id, person_id: $personId,
                tmt_start: $selected->tmt_start->format('Y-m-d'), tmt_end: $selected->tmt_end?->format('Y-m-d'),
                position_name: $selected->position_name, position_type: $selected->position_type,
                functional_position_id: $selected->functional_position_id, functional_level_id: $selected->functional_level_id,
                functional_level_code: $selected->functionalLevel?->code, functional_level_name: $selected->functionalLevel?->name,
                sk_number: $selected->sk_number, sk_date: $selected->sk_date?->format('Y-m-d'),
                source: $selected->source, source_ref: $selected->source_ref,
                verified_by: $selected->verified_by, verified_at: $selected->verified_at->format('Y-m-d H:i:s'),
                coverage_history_ids: $coverageIds,
            );
        }

        return new PositionResolution($months, $assignments, $issues);
    }

    private function monthStart(string $month): DateTimeImmutable
    {
        return new DateTimeImmutable($month.'-01', new DateTimeZone('UTC'));
    }
}
