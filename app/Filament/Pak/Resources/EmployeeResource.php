<?php

namespace App\Filament\Pak\Resources;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\Services\EmployeeHistoryService;
use App\Domain\Pak\Services\EmployeeProfileService;
use App\Filament\Pak\Support\DukReferenceFields;
use App\Filament\Pak\Support\EmployeeForms;
use App\Models\EmployeePositionHistory;
use App\Models\EmployeeRankHistory;
use App\Models\Person;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmployeeResource extends Resource
{
    protected static ?string $slug = 'pak/employees';

    protected static ?string $model = Person::class;

    protected static ?string $navigationLabel = 'Data Pegawai';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Data Pegawai';

    protected static ?string $pluralModelLabel = 'Data Pegawai';

    protected static ?string $navigationGroup = 'DATA KEPEGAWAIAN';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function canViewAny(): bool
    {
        return Access::allows(auth()->user(), 'pak.employee.view');
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        Access::authorize(auth()->user(), 'pak.employee.view');

        return parent::getEloquentQuery();
    }

    private static function historyAction(bool $position): Action
    {
        $class = $position ? EmployeePositionHistory::class : EmployeeRankHistory::class;

        return Action::make($position ? 'positionHistory' : 'rankHistory')->label($position ? 'Catat/koreksi jabatan utama' : 'Catat/koreksi pangkat')
            ->authorize(fn () => Access::allows(auth()->user(), 'pak.employee.manage') && static::canViewAny())
            ->form([
                Select::make('supersedes_id')->label('Koreksi histori lama (kosong untuk entri baru)')->live()
                    ->options(function (Person $record) use ($class) {
                        Access::authorize(auth()->user(), 'pak.employee.view');

                        return $class::where('person_id', $record->id)->whereNull('superseded_at')->orderByDesc('tmt_start')->get()->mapWithKeys(fn ($r) => [$r->id => $r->tmt_start->format('Y-m-d').' · '.($r->rank_name ?? $r->position_name)])->all();
                    })
                    ->afterStateUpdated(function ($state, Set $set, Person $record) use ($class) {
                        Access::authorize(auth()->user(), 'pak.employee.view');
                        if (! $state) {
                            return;
                        }
                        $row = $class::where('person_id', $record->id)->findOrFail($state);
                        foreach (['rank_name', 'grade_code', 'position_name', 'position_type', 'functional_position_id', 'functional_level_id', 'tmt_start', 'tmt_end', 'sk_date', 'sk_number', 'source_ref'] as $field) {
                            $value = $row->$field;
                            $set($field, $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value);
                        }
                    }),
                ...DukReferenceFields::fields(true), ...EmployeeForms::history($position),
                Textarea::make('reason')->label('Alasan koreksi')->required(fn (Get $get) => filled($get('supersedes_id')))->maxLength(2000),
            ])->action(fn (Person $record, array $data) => app(EmployeeHistoryService::class)->record(auth()->user(), $record, $data, $position, $data['supersedes_id'] ?? null, $data['reason'] ?? null));
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('canonical_name')->label('Nama')->searchable(), TextColumn::make('nip')->label('NIP')->searchable()])
            ->actions([
                Action::make('reference')->label('Referensi DUK')->authorize(fn () => static::canViewAny())->form(DukReferenceFields::fields())->modalSubmitAction(false),
                Action::make('profile')->label('Profil')->authorize(fn () => Access::allows(auth()->user(), 'pak.employee.manage') && static::canViewAny())
                    ->fillForm(function (Person $record) {
                        Access::authorize(auth()->user(), 'pak.employee.view');

                        return $record->employeeProfile?->attributesToArray() ?? [];
                    })
                    ->form(EmployeeForms::profile())->action(fn (Person $record, array $data) => app(EmployeeProfileService::class)->save(auth()->user(), $record, $data)),
                Action::make('history')->label('Lihat profil/histori')->authorize(fn () => static::canViewAny())->modalSubmitAction(false)
                    ->modalContent(function (Person $record) {
                        Access::authorize(auth()->user(), 'pak.employee.view');

                        return view('filament.pak.employee-history', ['person' => $record->load(['employeeProfile', 'rankHistories', 'positionHistories'])]);
                    }),
                static::historyAction(false), static::historyAction(true),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => EmployeeResource\Pages\ListEmployees::route('/')];
    }
}
