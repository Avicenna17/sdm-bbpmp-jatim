<?php

namespace App\Filament\Pages;

use App\Models\ImportBatch;
use App\Models\ReportingPeriod;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class ImportHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $title = 'Histori Import';

    protected static ?string $navigationGroup = 'Pengelolaan Data';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.history-table';

    public string $sourceFilter = '';

    public function selectSourceFilter(string $source): void
    {
        abort_unless(in_array($source, ['', 'PERSONNEL_DUK', 'POSITION_REQUIREMENT'], true), 422);
        $this->sourceFilter = $source;
        if ($source === 'POSITION_REQUIREMENT') {
            $this->tableFilters['reporting_period_id']['value'] = null;
        }
        $this->updatedTableFilters();
    }

    public function sourceCounts(): array
    {
        $counts = ImportBatch::query()->select('source_type')->selectRaw('COUNT(*) AS total')->groupBy('source_type')->pluck('total', 'source_type');

        return ['' => $counts->sum(), 'PERSONNEL_DUK' => $counts['PERSONNEL_DUK'] ?? 0, 'POSITION_REQUIREMENT' => $counts['POSITION_REQUIREMENT'] ?? 0];
    }

    public static function canAccess(): bool
    {
        return Gate::allows('import.view');
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => ImportBatch::query()->with(['period', 'uploader', 'issues'])->when($this->sourceFilter !== '', fn ($query) => $query->where('source_type', $this->sourceFilter)))->columns([
            TextColumn::make('data_reference')->label('Periode / Versi')->getStateUsing(fn (ImportBatch $record) => $record->source_type === 'PERSONNEL_DUK' ? $record->period?->label : ($record->position_version ? 'Versi '.$record->position_version : 'Belum tersimpan'))->placeholder('-'), TextColumn::make('original_filename')->label('File')->searchable(), TextColumn::make('source_label')->label('Jenis data'), TextColumn::make('uploader.name')->label('Pengunggah'), TextColumn::make('status_label')->label('Status')->badge()->color(fn (ImportBatch $record) => match ($record->status) {
                'failed' => 'danger', 'validated' => 'info', 'committed' => 'success', default => 'gray'
            }), TextColumn::make('error_rows')->label('Perlu diperbaiki'), TextColumn::make('review_warning_rows')->label('Catatan'), TextColumn::make('created_at')->dateTime('d/m/Y H:i')->label('Diunggah')->sortable(),
        ])->filters([SelectFilter::make('reporting_period_id')->label('Periode DUK')->visible(fn () => $this->sourceFilter !== 'POSITION_REQUIREMENT')->options(fn () => ReportingPeriod::orderByDesc('period_month')->get()->pluck('label', 'id')), SelectFilter::make('status')->options(array_combine(['validated', 'committed', 'failed'], ['Siap disimpan', 'Sudah disimpan', 'Perlu diperbaiki']))])->deferFilters(false)->actions([Action::make('preview')->label('Detail')->url(fn (ImportBatch $record) => ManageData::getUrl(['batch' => $record->id]))])->defaultSort('id', 'desc');
    }
}
