<?php

namespace App\Filament\Pages;

use App\Domain\Dashboard\DashboardQuery;
use App\Domain\Export\ExportEligibility;
use App\Models\PersonnelSnapshot;
use App\Models\PositionDataset;
use App\Models\ReportingPeriod;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

class ExportData extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $title = 'Export Data';

    protected static ?string $navigationGroup = 'Pengelolaan Data';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.export-data';

    public string $source = 'personnel';

    public string $format = 'xlsx';

    public ?int $periodId = null;

    public ?int $positionVersionId = null;

    public array $filters = [];

    public static function canAccess(): bool
    {
        return Gate::any(['export.personnel', 'export.position_requirement']);
    }

    public function mount(): void
    {
        $this->positionVersionId = PositionDataset::current()->published_batch_id;
        $this->periodId = ReportingPeriod::where('status', 'published')->orderByDesc('period_month')->value('id');
        if (! Gate::allows('export.personnel')) {
            $this->source = 'positions';
        }
    }

    public function updatedSource(): void
    {
        $this->positionVersionId = PositionDataset::current()->published_batch_id;
        $this->resetValidation();
        $this->filters = [];
    }

    public function updatedPeriodId(): void
    {
        $this->filters = [];
    }

    public function updatedPositionVersionId(): void
    {
        $this->filters = [];
        $this->resetValidation();
    }

    private function exportFilters(): array
    {
        return ['position_version_id' => $this->positionVersionId] + $this->filters;
    }

    public function download()
    {
        $this->validate(['source' => 'required|in:personnel,positions', 'format' => 'required|in:csv,xlsx', 'positionVersionId' => 'nullable|integer|exists:import_batches,id', 'periodId' => ($this->source === 'personnel' ? 'required' : 'nullable').'|exists:reporting_periods,id']);
        Gate::authorize($this->source === 'personnel' ? 'export.personnel' : 'export.position_requirement');
        $reason = app(ExportEligibility::class)->reason(ReportingPeriod::find($this->periodId), $this->source, $this->exportFilters());
        if ($reason) {
            $this->addError('export', $reason);

            return null;
        }

        return redirect()->route('exports', ['source' => $this->source, 'format' => $this->format, 'position_version_id' => $this->source === 'positions' ? $this->positionVersionId : null, 'period_id' => $this->source === 'personnel' ? $this->periodId : null] + $this->filters);
    }

    protected function getViewData(): array
    {
        $fields = $this->source === 'personnel' ? ['employment_group' => 'Grup'] + DashboardQuery::FILTERS : ['position_type' => 'Jenis Jabatan', 'position_class' => 'Kelas Jabatan', 'requirement_status' => 'Status Kebutuhan'];
        $options = [];
        foreach ($fields as $field => $label) {
            $options[$field] = ($this->source === 'personnel' ? PersonnelSnapshot::where('reporting_period_id', $this->periodId) : PositionDataset::exportSnapshots($this->positionVersionId))->whereNotNull($field)->distinct()->orderBy($field)->pluck($field)->all();
        }

        return ['activePeriod' => ReportingPeriod::activePublished(), 'activePositionVersionId' => PositionDataset::current()->published_batch_id, 'positionVersions' => PositionDataset::exportVersions()->orderByDesc('published_at')->orderByDesc('id')->get(), 'exportBlockedReason' => app(ExportEligibility::class)->reason(ReportingPeriod::find($this->periodId), $this->source, $this->exportFilters()), 'periods' => ReportingPeriod::orderByDesc('period_month')->get(), 'fields' => $fields, 'options' => $options];
    }
}
