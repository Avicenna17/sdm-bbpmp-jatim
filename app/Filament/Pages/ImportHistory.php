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

    public static function canAccess(): bool
    {
        return Gate::allows('import.view');
    }

    public function table(Table $table): Table
    {
        return $table->query(ImportBatch::query()->with(['period', 'uploader', 'issues']))->columns([
            TextColumn::make('period.period_month')->label('Periode')->date('m/Y'), TextColumn::make('original_filename')->label('File')->searchable(), TextColumn::make('source_label')->label('Jenis data'), TextColumn::make('uploader.name')->label('Pengunggah'), TextColumn::make('status_label')->label('Status')->badge()->color(fn (ImportBatch $record) => match ($record->status) { 'failed' => 'danger', 'validated' => 'info', 'committed' => 'success', default => 'gray' }), TextColumn::make('error_rows')->label('Perlu diperbaiki'), TextColumn::make('review_warning_rows')->label('Catatan'), TextColumn::make('created_at')->dateTime('d/m/Y H:i')->label('Diunggah')->sortable(),
        ])->filters([SelectFilter::make('reporting_period_id')->label('Periode')->options(fn () => ReportingPeriod::orderByDesc('period_month')->get()->pluck('label', 'id')), SelectFilter::make('status')->options(array_combine(['validated', 'committed', 'failed'], ['Siap disimpan', 'Sudah disimpan', 'Perlu diperbaiki']))])->actions([Action::make('preview')->label('Detail')->url(fn (ImportBatch $record) => ManageData::getUrl(['batch' => $record->id]))])->defaultSort('id', 'desc');
    }
}
