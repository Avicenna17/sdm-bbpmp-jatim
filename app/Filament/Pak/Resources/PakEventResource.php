<?php

namespace App\Filament\Pak\Resources;

use App\Filament\Pak\Support\ActivityDetailPresenter;
use App\Models\PakEvent;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class PakEventResource extends Resource
{
    protected static ?string $slug = 'pak/audit';

    protected static ?string $model = PakEvent::class;

    protected static ?string $navigationGroup = 'AUDIT & AKTIVITAS';

    protected static ?string $navigationLabel = 'Riwayat Aktivitas';

    protected static ?string $modelLabel = 'Riwayat Aktivitas';

    protected static ?string $pluralModelLabel = 'Riwayat Aktivitas';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    public static function getEloquentQuery(): Builder
    {
        abort_unless(static::canViewAny(), 403);

        return parent::getEloquentQuery()->with('actor');
    }

    public static function activityLabel(string $code): string
    {
        return match ($code) {
            'ACCOUNT_REACTIVATED' => 'Akun diaktifkan', 'ACCOUNT_DEACTIVATED' => 'Akun dinonaktifkan',
            'HISTORY_CORRECTED' => 'Histori dikoreksi', 'HISTORY_VERIFIED' => 'Histori diverifikasi',
            'PROFILE_VERIFIED' => 'Profil diverifikasi', 'MASTER_SAVED' => 'Master disimpan',
            'PAK_CASE_CREATED' => 'Draf PAK dibuat', 'PAK_SNAPSHOT_REFRESHED' => 'Snapshot PAK diperbarui',
            'RULE_METADATA_SAVED' => 'Metadata aturan disimpan', 'USER_PERSON_LINKED' => 'Pemetaan pegawai diperbarui',
            'PAK_CASE_EVIDENCE_UPLOADED' => 'Dokumen pendukung ditambahkan',
            'SKP_IMPORT_COMMITTED' => 'Impor SKP dikomit',
            'PERFORMANCE_EVALUATION_CORRECTED' => 'Evaluasi kinerja dikoreksi',
            'PREDICATE_RULE_CREATED' => 'Aturan predikat dibuat',
            'PREDICATE_RULE_UPDATED' => 'Aturan predikat diperbarui',
            'COEFFICIENT_RULE_CREATED' => 'Aturan koefisien dibuat',
            'COEFFICIENT_RULE_UPDATED' => 'Aturan koefisien diperbarui',
            'RULE_SET_COPIED' => 'Versi aturan disalin',
            'CONVERSION_RUN_SAVED' => 'Konversi disimpan',
            'CONVERSION_RUN_REVIEW_ASSESSED' => 'Tinjauan konversi dicatat',
            'CONVERSION_RUN_REPLACED' => 'Revisi konversi dibuat',
            default => $code,
        };
    }

    public static function objectLabel(PakEvent $record): string
    {
        $type = match ($record->subject_type) {
            'User' => 'Pengguna', 'EmployeeProfile' => 'Profil pegawai', 'EmployeeRankHistory' => 'Histori pangkat',
            'EmployeePositionHistory' => 'Histori jabatan', 'FunctionalCategory' => 'Kategori JF',
            'FunctionalLevel' => 'Jenjang JF', 'FunctionalPosition' => 'Jabatan fungsional',
            'FunctionalPositionLevel' => 'Pemetaan jabatan dan jenjang', 'PakRuleSet' => 'Aturan PAK',
            'PakCase' => 'Draf PAK',
            'PakConversionRun' => 'Run konversi', 'PakSkpImportBatch' => 'Batch impor SKP',
            'PerformanceEvaluation' => 'Evaluasi kinerja', 'PerformancePredicateRule' => 'Aturan predikat',
            'AkCoefficientRule' => 'Aturan koefisien',
            default => 'Objek audit',
        };

        return $type.' #'.$record->subject_id;
    }

    public static function timezoneLabel(): string
    {
        return config('app.timezone') === 'Asia/Jakarta' ? 'WIB' : config('app.timezone');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort(fn (Builder $query) => $query->orderByDesc('occurred_at')->orderByDesc('id'))
            ->columns([
                TextColumn::make('occurred_at')->label('Waktu ('.static::timezoneLabel().')')->dateTime('d/m/Y H:i:s', timezone: config('app.timezone')),
                TextColumn::make('actor.name')->label('Pengguna')->searchable(),
                TextColumn::make('event_code')->label('Aktivitas')->formatStateUsing(fn (string $state) => static::activityLabel($state))->searchable(),
                TextColumn::make('subject_type')->label('Objek')->formatStateUsing(fn (PakEvent $record) => static::objectLabel($record))->searchable(['subject_type', 'subject_id']),
            ])->filters([
                SelectFilter::make('event_code')->label('Jenis Aktivitas')->options(function () {
                    abort_unless(static::canViewAny(), 403);

                    return static::getEloquentQuery()->select('event_code')->distinct()->orderBy('event_code')->pluck('event_code')->mapWithKeys(fn ($code) => [$code => static::activityLabel($code)])->all();
                }),
                Filter::make('period')->label('Periode Aktivitas')->form([
                    Fieldset::make('Periode Aktivitas')->columns(1)->schema([
                        DatePicker::make('from')->label('Tanggal mulai')->rules(['date_format:Y-m-d']),
                        DatePicker::make('until')->label('Tanggal akhir')->rules(['date_format:Y-m-d'])->afterOrEqual(fn (Get $get) => $get('from') ?? ''),
                    ]),
                ])->query(function (Builder $query, array $data): Builder {
                    $rules = ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d']];
                    if (filled($data['from'] ?? null)) {
                        $rules['until'][] = 'after_or_equal:from';
                    }
                    // Fail closed for malformed query-string filters; form submission reports validation errors.
                    if (Validator::make($data, $rules)->fails()) {
                        return $query->whereRaw('1 = 0');
                    }
                    $timezone = config('app.timezone');
                    // Existing audit timestamps are written by Eloquent in the application timezone.
                    if (filled($data['from'] ?? null)) {
                        $query->where('occurred_at', '>=', CarbonImmutable::parse($data['from'], $timezone)->startOfDay()->format('Y-m-d H:i:s'));
                    }
                    if (filled($data['until'] ?? null)) {
                        $query->where('occurred_at', '<', CarbonImmutable::parse($data['until'], $timezone)->startOfDay()->addDay()->format('Y-m-d H:i:s'));
                    }

                    return $query;
                }),
            ])->deferFilters()
            ->actions([Action::make('detail')->label('Detail')->authorize('view')->modalHeading('Detail Aktivitas')->modalCancelActionLabel('Tutup')->modalWidth('3xl')->modalSubmitAction(false)
                ->modalContent(fn (PakEvent $record) => view('filament.pak.audit-detail', ['detail' => app(ActivityDetailPresenter::class)->present($record)]))]);
    }

    public static function getPages(): array
    {
        return ['index' => PakEventResource\Pages\ListEvents::route('/')];
    }
}
