<?php

namespace App\Filament\Resources;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Role & Permission';

    protected static ?string $navigationGroup = 'Manajemen Pengguna';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('role.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny() && $record->name !== 'Super Admin';
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->label('Nama role')->required()->maxLength(255)->unique(ignoreRecord: true), CheckboxList::make('permissions')->label('Permission')->relationship('permissions', 'name')->columns(2)->columnSpanFull()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Role')->weight(FontWeight::Bold), TextColumn::make('permissions_count')->counts('permissions')->label('Jumlah permission')])->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => RoleResource\Pages\ListRoles::route('/'), 'create' => RoleResource\Pages\CreateRole::route('/create'), 'edit' => RoleResource\Pages\EditRole::route('/{record}/edit')];
    }
}
