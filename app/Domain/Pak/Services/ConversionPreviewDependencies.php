<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\DTOs\MonthlyCoverage;
use App\Models\EmployeePositionHistory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\PerformanceEvaluation;
use Illuminate\Database\Eloquent\Model;

/** Evidence reader only. Selection algorithms remain in the accepted resolvers. */
final class ConversionPreviewDependencies
{
    /** @param list<string> $fields @return array<string, int|string|bool|null> */
    public function fields(Model $row, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $value = $row->getRawOriginal($field);
            if (in_array($field, ['tmt_start', 'tmt_end', 'sk_date', 'coverage_start', 'coverage_end', 'source_date', 'source_position_tmt', 'source_sk_date', 'period_start', 'period_end', 'employee_data_as_of', 'valid_from', 'valid_until'], true)
                && is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} 00:00:00\z/', $value)) {
                $value = substr($value, 0, 10);
            }
            $result[$field] = $value;
        }

        return $result;
    }

    /** @return array{sources: list<array>, histories: list<array>, levels: list<array>, positions: list<array>} */
    public function read(int $personId, MonthlyCoverage $coverage): array
    {
        $sources = [];
        foreach ($coverage->year_partitions as $partition) {
            $rows = PerformanceEvaluation::where('person_id', $personId)->where('performance_year', $partition->year)
                ->where('status', 'CURRENT')->where('source_kind', 'OFFICIAL')->whereIn('evaluation_type', ['ANNUAL', 'Q1', 'Q2', 'Q3', 'Q4'])->orderBy('id')->get();
            foreach ($rows as $row) {
                if ($row->evaluation_type->value !== 'ANNUAL' && (((int) substr($row->evaluation_type->value, 1) - 1) * 3 + 1) > $partition->end_month) {
                    continue;
                }
                $sources[] = $this->fields($row, ['id', 'person_id', 'performance_year', 'evaluation_type', 'status', 'source_kind', 'predicate_code',
                    'source_identity_fingerprint', 'current_source_identity_fingerprint', 'source_record_identifier', 'superseded_at', 'superseded_by_user_id', 'content_fingerprint', 'coverage_start', 'coverage_end', 'source_reference', 'source_date',
                    'source_position_tmt', 'source_sk_number', 'source_sk_date', 'source_position_name', 'source_functional_level_label', 'employee_position_history_id']);
            }
        }
        // Explicit requested proof IDs remain in source evidence. Never expose a foreign target.
        // Keep same-person candidates complete; proof selection belongs to the source resolver.
        $rows = EmployeePositionHistory::where('person_id', $personId)->orderBy('id')->get();
        $histories = [];
        foreach ($rows as $row) {
            $histories[] = $this->fields($row, ['id', 'person_id', 'tmt_start', 'tmt_end', 'position_type', 'position_name', 'functional_position_id', 'functional_level_id',
                'sk_number', 'sk_date', 'source', 'source_ref', 'verified_by', 'verified_at', 'superseded_at', 'supersedes_id']);
        }
        $levels = FunctionalLevel::whereIn('id', array_filter(array_column($histories, 'functional_level_id')))->orderBy('id')->get()
            ->map(fn ($row) => $this->fields($row, ['id', 'functional_category_id', 'code', 'name', 'is_active']))->all();
        $positions = FunctionalPosition::whereIn('id', array_filter(array_column($histories, 'functional_position_id')))->orderBy('id')->get()
            ->map(fn ($row) => $this->fields($row, ['id', 'code', 'name', 'is_active']))->all();

        return ['sources' => $sources, 'histories' => $histories, 'levels' => $levels, 'positions' => $positions];
    }
}
