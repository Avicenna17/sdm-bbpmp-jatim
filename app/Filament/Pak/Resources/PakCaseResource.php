<?php

namespace App\Filament\Pak\Resources;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\Enums\PakPurposeType;
use App\Domain\Pak\Queries\EmployeeReferenceQuery;
use App\Filament\Pak\Support\EmployeeForms;
use App\Models\PakCase;
use App\Models\PakRuleSet;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PakCaseResource extends Resource
{
    protected static ?string $slug = 'pak/cases';

    protected static ?string $model = PakCase::class;

    protected static ?string $navigationLabel = 'Draft PAK';

    protected static ?string $modelLabel = 'Draft PAK';

    protected static ?string $navigationGroup = 'Draft PAK';

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    public static function getEloquentQuery(): Builder
    {
        Access::authorize(auth()->user(), 'pak.case.view');

        return parent::getEloquentQuery()->with('employeeSnapshot');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Tujuan pemeriksaan')->schema([
                EmployeeForms::person()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated()->live()->afterStateUpdated(fn (Set $set) => $set('duk_snapshot_id', null)),
                Select::make('purpose_type')->label('Tujuan pemeriksaan')->options(PakPurposeType::options())->default('ROUTINE')->required(),
                DatePicker::make('period_start')->label('Awal periode')->required(),
                DatePicker::make('period_end')->label('Akhir periode')->required()->afterOrEqual('period_start'),
                DatePicker::make('employee_data_as_of')->label('Tanggal acuan data pegawai')->required()->live()->afterStateUpdated(fn (Set $set) => $set('duk_snapshot_id', null)),
                Select::make('pak_rule_set_id')->label('Metadata rule set (opsional)')->options(function () {
                    if (! Access::allows(auth()->user(), 'pak.rule-set.view')) {
                        return [];
                    }

                    return PakRuleSet::where('status', 'DRAFT')->get(['id', 'code', 'version'])->mapWithKeys(fn ($r) => [$r->id => $r->code.' / '.$r->version])->all();
                }),
                Textarea::make('notes')->label('Catatan')->maxLength(10000),
                Placeholder::make('draft_notice')->content('Draft boleh belum lengkap. Tujuan pemeriksaan bukan hasil eligibility atau keputusan kenaikan.'),
            ])->columns(2),
            Section::make('Referensi DUK published — opsional')->schema([
                TextInput::make('reference_page')->label('Halaman arsip')->numeric()->minValue(1)->default(1)->live()->dehydrated(false),
                Select::make('duk_snapshot_id')->label('Pilih referensi')->live()->options(function (Get $get) {
                    if (! $get('person_id') || ! $get('employee_data_as_of')) {
                        return [];
                    }
                    $rows = app(EmployeeReferenceQuery::class)->search(auth()->user(), (int) $get('person_id'), $get('employee_data_as_of'), '', (int) ($get('reference_page') ?: 1));

                    return collect($rows->items())->mapWithKeys(fn ($r) => [$r['id'] => $r['period_month'].' · '.$r['name_at_period']])->all();
                })->getOptionLabelUsing(function ($value, Get $get) {
                    if (! $value || ! $get('person_id') || ! $get('employee_data_as_of')) {
                        return null;
                    }
                    $r = app(EmployeeReferenceQuery::class)->find(auth()->user(), (int) $value, (int) $get('person_id'), $get('employee_data_as_of'))->toArray();

                    return $r['period_month'].' · '.$r['name_at_period'];
                }),
                Placeholder::make('reference_preview')->label('Kandidat sumber')->content(function (Get $get) {
                    if (! $get('duk_snapshot_id') || ! $get('person_id') || ! $get('employee_data_as_of')) {
                        return 'Identitas canonical-current digunakan bila referensi tidak dipilih; belum diverifikasi sebagai identitas historis.';
                    }

                    return view('filament.pak.duk-reference', ['reference' => app(EmployeeReferenceQuery::class)->find(auth()->user(), (int) $get('duk_snapshot_id'), (int) $get('person_id'), $get('employee_data_as_of'))->toArray()]);
                }),
            ]),
            Section::make('Organisasi historis — opsional')->description('Kosongkan bila sumber bertanggal belum tersedia. Profil current/default tidak otomatis digunakan.')->schema([
                TextInput::make('organization.institution_name')->label('Instansi historis')->maxLength(255),
                TextInput::make('organization.work_unit')->label('Unit kerja historis')->maxLength(255),
                TextInput::make('organization.source_reference')->label('Referensi dokumen')->maxLength(255),
                DatePicker::make('organization.source_date')->label('Tanggal dokumen sumber'),
                DatePicker::make('organization.valid_from')->label('Berlaku mulai'),
                DatePicker::make('organization.valid_until')->label('Berlaku sampai')->helperText('Jika akhir kosong, sumber hanya menyatakan keadaan tepat pada tanggal mulai.'),
            ])->columns(2),
            Textarea::make('reason')->label('Alasan perubahan dan refresh snapshot')->required(fn (string $operation) => $operation === 'edit')->visible(fn (string $operation) => $operation === 'edit')->maxLength(2000),
            Placeholder::make('snapshot_preview')->label('Perbandingan snapshot')->content(fn ($livewire) => view('filament.pak.snapshot-comparison', ['before' => $livewire->beforeSnapshot ?? [], 'after' => $livewire->previewValues ?? []]))->visible(fn (string $operation) => $operation === 'edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('ID draft'), TextColumn::make('employeeSnapshot.name')->label('Nama saat snapshot')->searchable(),
            TextColumn::make('purpose_type')->label('Tujuan')->badge(), TextColumn::make('period_start')->label('Awal')->date(),
            TextColumn::make('period_end')->label('Akhir')->date(), TextColumn::make('status')->badge(),
        ])->actions([ViewAction::make()->url(fn (PakCase $record) => static::getUrl('view', ['record' => $record])), EditAction::make()->url(fn (PakCase $record) => static::getUrl('edit', ['record' => $record]))]);
    }

    public static function getPages(): array
    {
        return ['index' => PakCaseResource\Pages\ListCases::route('/'), 'create' => PakCaseResource\Pages\CreateCase::route('/create'), 'view' => PakCaseResource\Pages\ViewCase::route('/{record}'), 'edit' => PakCaseResource\Pages\EditCase::route('/{record}/edit')];
    }
}
