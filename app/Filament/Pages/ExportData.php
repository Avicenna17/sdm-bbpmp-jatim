<?php

namespace App\Filament\Pages;

use App\Domain\Dashboard\DashboardQuery;
use App\Models\PersonnelSnapshot;
use App\Models\PositionRequirementSnapshot;
use App\Models\ReportingPeriod;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

class ExportData extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $title = 'Export Data';

    protected static ?string $navigationGroup = 'Pengelolaan Data';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.export-data';

    public string $source = 'personnel';

    public string $format = 'xlsx';

    public ?int $periodId = null;

    public array $filters = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->canAny(['export.personnel', 'export.position_requirement']) ?? false;
    }

    public function mount(): void
    {
        $this->periodId = ReportingPeriod::orderByDesc('period_month')->value('id');
        if (! auth()->user()->can('export.personnel')) {
            $this->source = 'positions';
        }
    }

    public function updatedSource(): void
    {
        $this->filters = [];
    }

    public function updatedPeriodId(): void
    {
        $this->filters = [];
    }

    public function download()
    {
        $this->validate(['source' => 'required|in:personnel,positions', 'format' => 'required|in:csv,xlsx', 'periodId' => 'required|exists:reporting_periods,id']);
        Gate::authorize($this->source === 'personnel' ? 'export.personnel' : 'export.position_requirement');

        return redirect()->route('exports', ['source' => $this->source, 'format' => $this->format, 'period_id' => $this->periodId] + $this->filters);
    }

    protected function getViewData(): array
    {
        $fields = $this->source === 'personnel' ? ['employment_group' => 'Grup'] + DashboardQuery::FILTERS : ['position_type' => 'Jenis Jabatan', 'position_class' => 'Kelas Jabatan', 'requirement_status' => 'Status Kebutuhan'];
        $model = $this->source === 'personnel' ? PersonnelSnapshot::class : PositionRequirementSnapshot::class;
        $options = [];
        foreach ($fields as $field => $label) {
            $options[$field] = $model::where('reporting_period_id', $this->periodId)->whereNotNull($field)->distinct()->orderBy($field)->pluck($field)->all();
        }

        return ['periods' => ReportingPeriod::orderByDesc('period_month')->get(), 'fields' => $fields, 'options' => $options];
    }
}
