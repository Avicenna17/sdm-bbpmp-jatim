<?php

namespace App\Filament\Pak\Resources;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\Services\PakRuleSetService;
use App\Models\PakRuleSet;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PakRuleSetResource extends Resource
{
    protected static ?string $slug = 'pak/rule-sets';

    protected static ?string $model = PakRuleSet::class;

    protected static ?string $navigationGroup = 'REFERENSI PAK';

    protected static ?string $navigationLabel = 'Aturan PAK';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Aturan PAK';

    protected static ?string $pluralModelLabel = 'Aturan PAK';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function fields(): array
    {
        return [TextInput::make('code')->label('Kode')->required()->maxLength(100), TextInput::make('name')->label('Nama')->required()->maxLength(255),
            TextInput::make('version')->label('Versi')->required()->maxLength(50), DatePicker::make('valid_from')->label('Berlaku mulai (boleh kosong)'),
            DatePicker::make('valid_until')->label('Berlaku sampai (boleh kosong)'), Textarea::make('reference')->label('Referensi')->maxLength(10000),
            Placeholder::make('draft_notice')->content('Metadata DRAFT; bukan aturan aktif untuk perhitungan. Versi terpakai dikunci. Tanggal belum terverifikasi boleh kosong.')];
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::fields());
    }

    public static function table(Table $table): Table
    {
        return $table->description('Metadata versi aturan Phase 01; bukan aturan aktif atau engine perhitungan.')->columns([TextColumn::make('code')->searchable(), TextColumn::make('name')->label('Nama'), TextColumn::make('version')->label('Versi'), TextColumn::make('valid_from')->date(), TextColumn::make('valid_until')->date(), TextColumn::make('status')->badge()])
            ->actions([
                EditAction::make()->using(fn (PakRuleSet $record, array $data) => app(PakRuleSetService::class)->save(auth()->user(), $data, $record->id)),
                Action::make('copy')->label('Salin versi')->authorize(fn () => Access::allows(auth()->user(), 'pak.rule-set.manage'))
                    ->fillForm(fn (PakRuleSet $record) => array_replace($record->attributesToArray(), ['version' => '']))
                    ->form(static::fields())->action(fn (array $data) => app(PakRuleSetService::class)->save(auth()->user(), $data)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => PakRuleSetResource\Pages\ManageRuleSets::route('/')];
    }
}
