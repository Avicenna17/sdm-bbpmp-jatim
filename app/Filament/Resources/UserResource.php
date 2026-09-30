<?php

namespace App\Filament\Resources;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'User';

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'User';

    protected static ?string $navigationGroup = 'Manajemen Pengguna';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('user.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->label('Nama')->required()->maxLength(255), TextInput::make('email')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
            TextInput::make('password')->label('Password baru')->password()->minLength(12)->required(fn (string $operation) => $operation === 'create')->dehydrated(fn ($state) => filled($state))->afterStateHydrated(fn ($component) => $component->state(null)),
            Select::make('roles')->label('Role')->relationship('roles', 'name')->multiple()->preload()->required()->disabled(fn ($record) => $record?->id === auth()->id())->helperText('Role akun sendiri tidak dapat diubah di sini.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Nama')->searchable(), TextColumn::make('email')->searchable(), TextColumn::make('roles.name')->badge()->label('Role')])->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => UserResource\Pages\ListUsers::route('/'), 'create' => UserResource\Pages\CreateUser::route('/create'), 'edit' => UserResource\Pages\EditUser::route('/{record}/edit')];
    }
}
