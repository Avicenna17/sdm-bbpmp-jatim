<?php

namespace App\Filament\Resources;

use App\Domain\Pak\Services\UserPersonLinkService;
use App\Domain\Users\AccountStatusService;
use App\Models\Person;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

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
        return Gate::allows('user.manage');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->label('Nama')->required()->maxLength(255), TextInput::make('email')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
            TextInput::make('password')->label('Password baru')->password()->minLength(12)->required(fn (string $operation) => $operation === 'create')->dehydrated(fn ($state) => filled($state))->afterStateHydrated(fn ($component) => $component->state(null)),
            Select::make('roles')->label('Role')->relationship('roles', 'name')->multiple()->preload()->required()->disabled(fn ($record) => $record?->id === Auth::id())->helperText('Role akun sendiri tidak dapat diubah di sini.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Nama')->searchable(), TextColumn::make('email')->searchable(), TextColumn::make('roles.name')->badge()->label('Role')->color(fn (string $state): string => match ($state) {
            'Super Admin' => 'primary', 'Admin SDM' => 'info', 'Admin PAK' => 'success', 'Internal Viewer' => 'warning', default => 'gray',
        }), IconColumn::make('is_active')->label('Aktif')->boolean()])->actions([
            EditAction::make(),
            Action::make('linkPerson')->label('Pemetaan pegawai')
                ->authorize(fn () => Gate::allows('user.manage') && Auth::user()?->is_active)
                ->fillForm(fn (User $record) => ['person_id' => $record->person_id])
                ->form([
                    Select::make('person_id')->label('Person (kosong untuk melepas)')->searchable()
                        ->getSearchResultsUsing(function (string $search) {
                            Gate::authorize('user.manage');

                            return Person::where(fn ($q) => $q->where('canonical_name', 'like', '%'.mb_substr($search, 0, 100).'%')->orWhere('nip', 'like', '%'.mb_substr($search, 0, 100).'%'))->limit(20)->get()->mapWithKeys(fn ($p) => [$p->id => $p->canonical_name.' · '.($p->nip ?? 'Tanpa NIP')])->all();
                        })->getOptionLabelUsing(function ($value) {
                            Gate::authorize('user.manage');
                            $p = Person::find($value);

                            return $p ? $p->canonical_name.' · '.($p->nip ?? 'Tanpa NIP') : null;
                        }),
                    Textarea::make('reason')->label('Alasan/verifikasi identitas')->required()->maxLength(2000),
                ])->action(fn (User $record, array $data) => app(UserPersonLinkService::class)->link(Auth::user(), $record, filled($data['person_id'] ?? null) ? (int) $data['person_id'] : null, $data['reason'])),
            Action::make('accountStatus')->label(fn (User $record) => $record->is_active ? 'Nonaktifkan' : 'Aktifkan')
                ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
                ->authorize(fn () => Gate::allows('user.manage') && Auth::user()?->is_active)
                ->form([Textarea::make('reason')->label('Alasan')->required()->maxLength(2000)])
                ->action(fn (User $record, array $data) => app(AccountStatusService::class)->change(Auth::user(), $record, ! $record->is_active, $data['reason'])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => UserResource\Pages\ListUsers::route('/'), 'create' => UserResource\Pages\CreateUser::route('/create'), 'edit' => UserResource\Pages\EditUser::route('/{record}/edit')];
    }
}
