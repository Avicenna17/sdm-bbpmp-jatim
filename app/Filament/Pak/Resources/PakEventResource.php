<?php

namespace App\Filament\Pak\Resources;

use App\Models\PakEvent;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PakEventResource extends Resource
{
    protected static ?string $slug = 'pak/audit';

    protected static ?string $model = PakEvent::class;

    protected static ?string $navigationGroup = 'Audit';

    protected static ?string $navigationLabel = 'Audit Foundation';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([TextColumn::make('occurred_at')->label('Waktu')->dateTime(), TextColumn::make('actor.name')->label('Petugas'), TextColumn::make('event_code')->label('Aksi')->searchable(), TextColumn::make('subject_type')->label('Subjek'), TextColumn::make('subject_id')->label('ID')])
            ->actions([Action::make('detail')->label('Detail')->authorize('view')->modalSubmitAction(false)->modalContent(fn (PakEvent $record) => view('filament.pak.audit-detail', ['payload' => $record->payload]))]);
    }

    public static function getPages(): array
    {
        return ['index' => PakEventResource\Pages\ListEvents::route('/')];
    }
}
