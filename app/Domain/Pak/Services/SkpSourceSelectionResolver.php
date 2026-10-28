<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\DTOs\SkpPositionMonthContext;
use App\Domain\Pak\DTOs\SkpRequestedYearPartition;
use App\Domain\Pak\DTOs\SkpSelectedSource;
use App\Domain\Pak\DTOs\SkpSourceSelection;
use App\Domain\Pak\Enums\PerformanceEvaluationStatus;
use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;
use App\Domain\Pak\Queries\ConversionDependencyReader;
use App\Models\EmployeePositionHistory;
use App\Models\PerformanceEvaluation;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class SkpSourceSelectionResolver
{
    /** Read-only internal API: the caller resolves case containment and position-month context. */
    public function resolve(int $personId, SkpRequestedYearPartition $partition, ?SkpPositionMonthContext $positions = null, ?ConversionDependencyReader $reader = null): SkpSourceSelection
    {
        if ($personId < 1) {
            throw new InvalidArgumentException('INVALID_SOURCE_SELECTION_INPUT');
        }
        $reader ??= app(ConversionPreviewDependencies::class);
        $months = $partition->months();
        $candidates = $reader->sourceCandidates(fn (Builder $query) => $query->where('person_id', $personId)
            ->where('performance_year', $partition->year)->where('status', PerformanceEvaluationStatus::CURRENT)->where('source_kind', PerformanceSourceKind::OFFICIAL)
            ->whereIn('evaluation_type', [PerformanceEvaluationType::ANNUAL, PerformanceEvaluationType::Q1,
                PerformanceEvaluationType::Q2, PerformanceEvaluationType::Q3, PerformanceEvaluationType::Q4]));
        // BR-SKP-002/009: source_date and formal coverage do not filter applied months.
        $priority = ['ANNUAL' => 5, 'Q4' => 4, 'Q3' => 3, 'Q2' => 2, 'Q1' => 1];
        $candidates = $candidates->filter(fn ($row) => $row->evaluation_type === PerformanceEvaluationType::ANNUAL
            || (($priority[$row->evaluation_type->value] - 1) * 3 + 1) <= $partition->end_month);
        if ($candidates->isEmpty()) {
            return $this->issue('SOURCE_NOT_FOUND', $months);
        }
        $highest = $candidates->max(fn ($row) => $priority[$row->evaluation_type->value]);
        $candidates = $candidates->filter(fn ($row) => $priority[$row->evaluation_type->value] === $highest)->values();
        foreach ($candidates as $row) {
            if (! $this->integrity($row, $partition)) {
                return $this->issue('SOURCE_INTEGRITY_ERROR', $months);
            }
        }
        $multiple = $candidates->count() > 1;
        if ($multiple && $highest !== 5) {
            return $this->issue('AMBIGUOUS_SOURCE_SELECTION', $months);
        }

        $proofs = [];
        foreach ($candidates as $row) {
            // Context disambiguates multiple annuals; it never adds prerequisites to a single source.
            // An existing explicit link must still be validated, even for a single source.
            if ($multiple || $row->employee_position_history_id !== null) {
                $proof = $this->historyProof($row, $reader);
                if ($proof === null) {
                    return $this->issue('SOURCE_POSITION_LINK_REQUIRES_REVIEW', $months);
                }
                $proofs[$row->id] = $proof;
            }
        }
        $selections = [];
        $issues = [];
        foreach ($months as $month) {
            $eligible = $candidates;
            if ($multiple) {
                $historyId = $positions?->history_by_month[$month] ?? null;
                if ($historyId === null) {
                    $issues[] = ['issue_code' => 'AMBIGUOUS_SOURCE_SELECTION', 'months' => [$month]];

                    continue;
                }
                $eligible = $candidates->filter(fn ($row) => $proofs[$row->id]['id'] === $historyId);
            }
            if ($eligible->count() !== 1) {
                $issues[] = ['issue_code' => $eligible->isEmpty() ? 'SOURCE_POSITION_LINK_REQUIRES_REVIEW' : 'AMBIGUOUS_SOURCE_SELECTION', 'months' => [$month]];

                continue;
            }
            $row = $eligible->sole();
            $proof = $proofs[$row->id] ?? null;
            $selections[] = new SkpSelectedSource((int) $row->id, (int) $row->person_id, $row->performance_year,
                $row->evaluation_type, $row->predicate_code, $row->source_identity_fingerprint, $row->content_fingerprint,
                $row->coverage_start->format('Y-m-d'), $row->coverage_end->format('Y-m-d'), $row->source_reference,
                $row->source_date?->format('Y-m-d'), [$month], $highest === 5 ? 'ANNUAL_PRIORITY' : 'LATEST_RELEVANT_QUARTER',
                $proof['id'] ?? null, $proof['method'] ?? null);
        }

        return new SkpSourceSelection($selections, $issues);
    }

    private function integrity(PerformanceEvaluation $row, SkpRequestedYearPartition $partition): bool
    {
        foreach (['source_identity_fingerprint', 'content_fingerprint'] as $field) {
            if (! is_string($row->$field) || ! preg_match('/\A[0-9a-f]{64}\z/', $row->$field)) {
                return false;
            }
        }
        foreach (['predicate_code', 'source_reference', 'source_record_identifier'] as $field) {
            if (! is_string($row->$field) || ! mb_check_encoding($row->$field, 'UTF-8') || ! preg_match('/[^\p{Z}\s]/u', $row->$field)) {
                return false;
            }
        }
        $start = $row->getRawOriginal('coverage_start');
        $end = $row->getRawOriginal('coverage_end');
        foreach (['coverage_start', 'coverage_end', 'source_date', 'source_position_tmt', 'source_sk_date'] as $field) {
            $date = $row->getRawOriginal($field);
            $coverage = in_array($field, ['coverage_start', 'coverage_end'], true);
            if ($date === null && ! $coverage) {
                continue;
            }
            // Eloquent date casts persist midnight on SQLite; MariaDB DATE returns only the date.
            if (! is_string($date) || ! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})(?: 00:00:00)?\z/', $date, $parts)
                || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || ($coverage && (int) $parts[1] !== $partition->year)) {
                return false;
            }
        }

        return $start <= $end && $row->current_source_identity_fingerprint === $row->source_identity_fingerprint
            && $row->superseded_at === null && $row->superseded_by_user_id === null;
    }

    /** @return array{id: int, method: string}|null */
    private function historyProof(PerformanceEvaluation $row, ConversionDependencyReader $reader): ?array
    {
        $explicit = $row->employee_position_history_id !== null;
        if (! $explicit && $row->source_position_tmt === null) {
            return null;
        }
        $histories = $reader->historyCandidates(function (Builder $query) use ($row, $explicit) {
            $query->where('person_id', $row->person_id)
                ->whereNotNull('verified_at')->whereNotNull('verified_by')->whereNull('superseded_at');
            if ($explicit) {
                $query->whereKey($row->employee_position_history_id);
            } else {
                $query->whereDate('tmt_start', $row->source_position_tmt->format('Y-m-d'));
            }

            return $query;
        })->filter(fn ($history) => $this->consistent($row, $history, false));
        if ($histories->count() !== 1 || ! $this->consistent($row, $histories->sole(), true)) {
            return null;
        }

        return ['id' => (int) $histories->sole()->id, 'method' => $explicit ? 'EXPLICIT_HISTORY_ID' : 'EXACT_TMT_PROVENANCE'];
    }

    private function consistent(PerformanceEvaluation $row, EmployeePositionHistory $history, bool $labels): bool
    {
        // SKP source_reference and history source_ref identify different documents, not a shared key.
        // Labels never reduce a candidate set: check them only after a unique structural match.
        $pairs = $labels ? [
            [$row->source_position_name, $history->position_name],
            [$row->source_functional_level_label, $history->getRelation('functionalLevel')?->name],
        ] : [
            [$row->source_position_tmt?->format('Y-m-d'), $history->tmt_start?->format('Y-m-d')],
            [$row->source_sk_number, $history->sk_number],
            [$row->source_sk_date?->format('Y-m-d'), $history->sk_date?->format('Y-m-d')],
        ];
        foreach ($pairs as [$source, $stored]) {
            if ($source !== null && $stored !== null && $source !== $stored) {
                return false;
            }
        }

        return true;
    }

    private function issue(string $code, array $months): SkpSourceSelection
    {
        return new SkpSourceSelection([], [['issue_code' => $code, 'months' => $months]]);
    }
}
