<?php

namespace App\Filament\Pages;

use App\Models\PersonnelSnapshot;
use App\Models\ReportingPeriod;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PersonnelData extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $title = 'Daftar Pegawai';

    protected static ?string $navigationGroup = 'Data Master';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.personnel-data';

    public function selectPersonnelGroup(string $group): void
    {
        abort_unless(in_array($group, ['', 'ASN', 'PPNPN', 'UNKNOWN'], true), 422);
        $this->tableFilters['employment_group']['value'] = $group ?: null;
        // Status belongs to the chosen group: clear only this dependent filter.
        $this->tableFilters['employment_status']['value'] = null;
        $this->updatedTableFilters();
    }

    public function personnelSummary(): array
    {
        // Use the same query as the table, before pagination, including its search.
        $records = $this->getFilteredTableQuery()->withoutEagerLoads()->get([
            'employment_status', 'gender', 'education_level', 'grade_code',
            'position_name', 'position_class', 'placement_current',
        ]);
        $total = $records->count();
        $charts = [];
        $titles = ['gender' => 'Jenis kelamin', 'employment_status' => 'Status kepegawaian', 'education_level' => 'Pendidikan', 'grade_code' => 'Golongan', 'position_name' => 'Jabatan', 'position_class' => 'Kelas jabatan', 'placement_current' => 'SK Tim Kerja (Baru)'];
        foreach ($titles as $field => $title) {
            $values = $records->countBy(function ($record) use ($field) {
                $value = $record->$field;

                return match (true) {
                    $value === null || $value === '' || $value === 'UNKNOWN' || $value === '-' => '-',
                    $field === 'gender' && $value === 'L' => 'Laki-laki',
                    $field === 'gender' && $value === 'P' => 'Perempuan',
                    $value === 'PPPK_PARUH_WAKTU' => 'PPPK Paruh Waktu',
                    default => (string) $value,
                };
            })->sortDesc();
            $charts[$field] = ['title' => $title, 'values' => $values->all()];
        }
        $periodId = $this->tableFilters['reporting_period_id']['value'] ?? null;

        return ['total' => $total, 'charts' => $charts,
            'period' => $periodId ? ReportingPeriod::find($periodId)?->label : 'Semua periode',
            'multiplePeriods' => ! $periodId,
            'positions' => $records->pluck('position_name')->filter(fn ($value) => filled($value) && $value !== '-')->unique()->count(),
            'placements' => $records->pluck('placement_current')->filter(fn ($value) => filled($value) && $value !== '-')->unique()->count(),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('personnel.view') ?? false;
    }

    public function table(Table $table): Table
    {
        $fields = ['name_at_period' => 'Nama', 'nip_at_period' => 'NIP/NIP3K', 'employment_group' => 'Grup', 'employment_status' => 'Status', 'gender' => 'Gender', 'education_level' => 'Pendidikan', 'rank_name' => 'Pangkat', 'grade_code' => 'Golongan', 'position_name' => 'Jabatan', 'position_class' => 'Kelas', 'placement_current' => 'SK Tim Kerja (Baru)', 'placement_initial' => 'SK Tim Kerja (Awal)', 'assignment_detail' => 'Detail Penempatan Awal', 'nip_note' => 'Keterangan NIP'];
        $columns = [TextColumn::make('period.period_month')->label('Periode')->date('m/Y')->sortable()];
        foreach ($fields as $field => $label) {
            $columns[] = TextColumn::make($field)->label($label)->formatStateUsing(fn ($state) => $state === 'UNKNOWN' ? '-' : $state)->placeholder('-')->searchable()->sortable()->toggleable();
        }
        $filters = [SelectFilter::make('reporting_period_id')->label('Periode')->options(fn () => ReportingPeriod::orderByDesc('period_month')->get()->pluck('label', 'id'))->default(fn () => ReportingPeriod::orderByDesc('period_month')->value('id'))];
        foreach (array_diff_key($fields, array_flip(['name_at_period', 'nip_at_period'])) as $field => $label) {
            $filters[] = SelectFilter::make($field)->label($label)->options(fn () => PersonnelSnapshot::whereNotNull($field)->distinct()->orderBy($field)->pluck($field, $field)->map(fn ($value) => $value === 'UNKNOWN' ? '-' : $value)->all())->searchable();
        }

        return $table->query(PersonnelSnapshot::query()->with('period'))->columns($columns)->filters($filters)->deferFilters(false)->defaultSort('name_at_period')->emptyStateHeading('Tidak ada data pegawai untuk filter ini')->emptyStateDescription('Periksa pilihan periode dan filter. Untuk menambahkan data, unggah DUK melalui Periode dan Import Data lalu pilih Simpan data.');
    }
}
