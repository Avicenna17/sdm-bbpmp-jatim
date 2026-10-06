<?php

namespace App\Domain\Dashboard;

use App\Models\PositionProjectionValue;
use App\Models\ReportingPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

class DashboardQuery
{
    public const FILTERS = ['employment_status' => 'Status', 'gender' => 'Jenis kelamin', 'education_level' => 'Pendidikan', 'grade_code' => 'Golongan', 'position_name' => 'Jabatan', 'position_class' => 'Kelas jabatan', 'placement_current' => 'Penempatan'];

    public function personnel(ReportingPeriod $period, array $filters = []): HasMany
    {
        $query = $period->personnel();
        foreach (array_merge(['employment_group'], array_keys(self::FILTERS)) as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        return $query;
    }

    private function breakdown(Builder|Relation $query, string $column): array
    {
        return (clone $query)->select($column)->selectRaw('COUNT(*) as total')->groupBy($column)->orderByDesc('total')->get()->map(fn ($r) => ['label' => $r->$column ?: 'Belum diisi', 'value' => (int) $r->total])->all();
    }

    public function get(ReportingPeriod $period, array $filters): array
    {
        abort_unless($period->status === 'published', 404);
        $query = $this->personnel($period, $filters);
        $charts = [];
        $options = [];
        foreach (self::FILTERS as $field => $label) {
            $values = $this->breakdown($query, $field);
            $charts[$field] = ['title' => $label, 'values' => $values];
            $options[$field] = $period->personnel()->whereNotNull($field)->distinct()->orderBy($field)->pluck($field)->all();
        }
        $positions = $period->positions();
        if (filled($filters['position_search'] ?? null)) {
            $positions->where('position_name', 'like', '%'.$filters['position_search'].'%');
        }
        if (filled($filters['position_type'] ?? null)) {
            $positions->where('position_type', $filters['position_type']);
        }
        $positionCount = (clone $positions)->count();
        $projections = PositionProjectionValue::query()->whereIn('position_requirement_snapshot_id', (clone $positions)->select('position_requirement_snapshots.id'))
            ->select('metric_type', 'projection_year')->selectRaw('SUM(value) as total, COUNT(value) as known')->groupBy('metric_type', 'projection_year')->orderBy('projection_year')->get()->groupBy('metric_type')
            ->map(fn ($rows) => $rows->map(fn ($v) => ['label' => (string) $v->projection_year, 'value' => $v->total === null ? null : (int) $v->total, 'known' => (int) $v->known, 'expected' => $positionCount])->all())->all();

        return ['total' => $query->count(), 'status' => $this->breakdown($query, 'employment_status'), 'unclassified' => $period->personnel()->where('employment_group', 'UNKNOWN')->count(), 'charts' => $charts, 'options' => $options,
            'positions' => ['count' => $positionCount, 'types' => $period->positions()->whereNotNull('position_type')->distinct()->orderBy('position_type')->pluck('position_type')->all(), 'retirement_total' => (int) (clone $positions)->sum('retirement_5y_total'), 'incumbents' => (int) (clone $positions)->sum('incumbent_count'), 'requirements' => (int) (clone $positions)->sum('requirement_count'), 'vacancies' => (int) (clone $positions)->sum('vacancy_count'),
                'status' => $this->breakdown($positions, 'requirement_status'), 'top' => (clone $positions)->select('position_name', 'position_type', 'position_class')->selectRaw('SUM(incumbent_count) as incumbents, SUM(requirement_count) as requirements, SUM(vacancy_count) as vacancies')->groupBy('position_name', 'position_type', 'position_class')->orderByDesc('vacancies')->orderBy('position_name')->get()->toArray(), 'projections' => $projections]];
    }
}
