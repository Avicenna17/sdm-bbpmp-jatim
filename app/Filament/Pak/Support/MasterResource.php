<?php

namespace App\Filament\Pak\Support;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\Services\FunctionalMasterService;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

abstract class MasterResource extends Resource
{
    protected static ?string $navigationGroup = 'REFERENSI PAK';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static string $masterType;

    public static function form(Form $form): Form
    {
        $type = static::$masterType;
        $fields = [];
        if ($type === 'mapping') {
            $fields = [
                Select::make('functional_position_id')->label('JF')->required()->options(function () {
                    Access::authorize(auth()->user(), 'pak.master.view');

                    return FunctionalPosition::pluck('name', 'id');
                }),
                Select::make('functional_level_id')->label('Jenjang')->required()->options(function () {
                    Access::authorize(auth()->user(), 'pak.master.view');

                    return FunctionalLevel::pluck('name', 'id');
                }),
            ];
        } else {
            $fields = [TextInput::make('code')->label('Kode identitas')->maxLength(100)->required($type !== 'position'), TextInput::make('name')->label('Nama')->required()->maxLength(255)];
            if ($type !== 'position') {
                $fields[] = TextInput::make('sort_order')->label('Urutan tampilan')->numeric()->default(0)->required()->minValue(0)->maxValue(65535);
            }
            if ($type === 'level') {
                $fields[] = Select::make('functional_category_id')->label('Kategori')->required()->options(function () {
                    Access::authorize(auth()->user(), 'pak.master.view');

                    return FunctionalCategory::pluck('name', 'id');
                });
            }
        }

        return $form->schema([...$fields, Toggle::make('is_active')->label('Aktif')->default(true)->required()]);
    }

    public static function saveRecord(array $data, ?int $id = null): Model
    {
        return app(FunctionalMasterService::class)->save(auth()->user(), static::$masterType, $data, $id);
    }

    public static function table(Table $table): Table
    {
        $columns = static::$masterType === 'mapping' ? [TextColumn::make('functionalPosition.name')->label('JF'), TextColumn::make('functionalLevel.name')->label('Jenjang')]
            : [TextColumn::make('code')->label('Kode')->searchable(), TextColumn::make('name')->label('Nama')->searchable()];

        return $table->columns([...$columns, IconColumn::make('is_active')->label('Aktif')->boolean()])
            ->actions([EditAction::make()->using(fn (Model $record, array $data) => static::saveRecord($data, $record->id))]);
    }
}
