<?php

namespace App\Filament\Pages;

use App\Models\PositionProjectionValue;
use App\Models\PositionRequirementSnapshot;
use App\Models\ReportingPeriod;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PositionData extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $title = 'Peta Jabatan dan Kebutuhan';

    protected static ?string $navigationGroup = 'Data Master';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.position-data';

    public function positionSummary(): array
    {
        $records = $this->getFilteredSortedTableQuery()->get();
        $series = [];
        foreach (['RETIREMENT', 'REQUIREMENT'] as $metric) {
            $values = $records->flatMap(fn ($record) => $record->projections)
                ->where('metric_type', $metric)->groupBy('projection_year')->sortKeys();
            $series[$metric] = $values->map(function ($items) use ($records) {
                $known = $items->whereNotNull('value');

                return ['value' => $known->isEmpty() ? null : $known->sum('value'),
                    'known' => $known->count(), 'total' => $records->count()];
            })->all();
        }

        return ['records' => $records, 'series' => $series,
            'maximum' => max(1, $records->flatMap(fn ($record) => [abs($record->incumbent_count ?? 0), abs($record->requirement_count ?? 0), abs($record->vacancy_count ?? 0)])->max() ?? 0),
            'multiplePeriods' => empty($this->tableFilters['reporting_period_id']['value'])];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('position_requirement.view') ?? false;
    }

    public function table(Table $table): Table
    {
        $fields = ['source_sequence' => 'No', 'parent_org' => 'Unit Organisasi Induk', 'retirement_age' => 'Usia Pensiun', 'retirement_5y_total' => 'Total Pensiun 5 Tahun', 'position_name' => 'Jabatan', 'position_type' => 'Jenis', 'position_class' => 'Kelas', 'incumbent_count' => 'Pemangku', 'requirement_count' => 'Kebutuhan', 'vacancy_count' => 'Kosong', 'requirement_status' => 'Status', 'work_unit' => 'Satuan Kerja'];
        $columns = [TextColumn::make('period.period_month')->label('Periode')->date('m/Y')->sortable()];
        foreach ($fields as $field => $label) {
            $columns[] = TextColumn::make($field)->label($label)->placeholder('-')->sortable()->searchable()->toggleable();
        }
        foreach (PositionProjectionValue::select('metric_type', 'projection_year')->distinct()->orderBy('metric_type')->orderBy('projection_year')->get() as $projection) {
            $metric = $projection->metric_type;
            $year = (int) $projection->projection_year;
            $columns[] = TextColumn::make('projection_'.$metric.'_'.$year)
                ->label(($metric === 'RETIREMENT' ? 'Pensiun ' : 'Proyeksi Kebutuhan ').$year)
                ->getStateUsing(fn (PositionRequirementSnapshot $record) => $record->projections->first(fn ($value) => $value->metric_type === $metric && (int) $value->projection_year === $year)?->value)
                ->placeholder('-')->toggleable();
        }
        $filters = [SelectFilter::make('reporting_period_id')->label('Periode')->options(fn () => ReportingPeriod::orderByDesc('period_month')->get()->pluck('label', 'id'))->default(fn () => ReportingPeriod::orderByDesc('period_month')->value('id'))];
        foreach (['position_type' => 'Jenis Jabatan', 'position_class' => 'Kelas', 'requirement_status' => 'Status'] as $field => $label) {
            $filters[] = SelectFilter::make($field)->label($label)->options(fn () => PositionRequirementSnapshot::whereNotNull($field)->distinct()->pluck($field, $field)->all());
        }

        return $table->query(PositionRequirementSnapshot::query()->with(['period', 'projections']))->columns($columns)->filters($filters)->deferFilters(false)->defaultSort('vacancy_count', 'desc')->emptyStateHeading('Belum ada peta jabatan');
    }
}
