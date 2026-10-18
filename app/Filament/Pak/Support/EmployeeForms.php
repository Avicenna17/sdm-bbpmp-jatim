<?php

namespace App\Filament\Pak\Support;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\Enums\EmployeePositionType;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use App\Models\Person;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;

final class EmployeeForms
{
    public static function person(): Select
    {
        return Select::make('person_id')->label('Pegawai')->required()->searchable()
            ->getSearchResultsUsing(function (string $search) {
                Access::authorize(auth()->user(), 'pak.employee.view');

                return Person::where(fn ($q) => $q->where('canonical_name', 'like', '%'.mb_substr($search, 0, 100).'%')->orWhere('nip', 'like', '%'.mb_substr($search, 0, 100).'%'))
                    ->limit(20)->get(['id', 'canonical_name', 'nip'])->mapWithKeys(fn ($p) => [$p->id => $p->canonical_name.' · '.($p->nip ?? 'Tanpa NIP')])->all();
            })->getOptionLabelUsing(function ($value) {
                Access::authorize(auth()->user(), 'pak.employee.view');
                $p = Person::find($value);

                return $p ? $p->canonical_name.' · '.($p->nip ?? 'Tanpa NIP') : null;
            });
    }

    public static function profile(): array
    {
        return [
            TextInput::make('karpeg_number')->label('Nomor Karpeg')->maxLength(100),
            TextInput::make('birth_place')->label('Tempat lahir')->maxLength(150),
            DatePicker::make('birth_date')->label('Tanggal lahir'),
            Select::make('gender')->label('Jenis kelamin')->options(['L' => 'Laki-laki', 'P' => 'Perempuan']),
            TextInput::make('institution_name')->label('Instansi saat ini/default')->maxLength(255),
            TextInput::make('work_unit_name')->label('Unit kerja saat ini/default')->maxLength(255),
            Textarea::make('source_reference')->label('Sumber verifikasi profil')->required()->maxLength(255),
            Placeholder::make('notice')->content('Metadata organisasi terkini tidak otomatis menjadi organisasi historis pada draft PAK.'),
        ];
    }

    public static function history(bool $position): array
    {
        $fields = $position ? [
            TextInput::make('position_name')->label('Jabatan utama/definitif')->required()->maxLength(255),
            Select::make('position_type')->label('Jenis jabatan utama')->options(EmployeePositionType::options())->required()->live(),
            Select::make('functional_position_id')->label('Jabatan fungsional')->visible(fn (Get $get) => $get('position_type') === 'JF')->required(fn (Get $get) => $get('position_type') === 'JF')->live()
                ->options(function () {
                    Access::authorize(auth()->user(), 'pak.employee.view');

                    return FunctionalPosition::where('is_active', true)->pluck('name', 'id');
                }),
            Select::make('functional_level_id')->label('Jenjang')->visible(fn (Get $get) => $get('position_type') === 'JF')->required(fn (Get $get) => $get('position_type') === 'JF')
                ->options(function (Get $get) {
                    Access::authorize(auth()->user(), 'pak.employee.view');

                    return FunctionalLevel::where('is_active', true)->whereIn('id', FunctionalPositionLevel::where('is_active', true)->where('functional_position_id', $get('functional_position_id'))->select('functional_level_id'))->pluck('name', 'id');
                }),
            Placeholder::make('main_only')->content('Hanya jabatan utama/definitif. Plt, Plh, penugasan simultan, dan jabatan rangkap belum dicatat di sini.'),
        ] : [TextInput::make('rank_name')->label('Pangkat')->required()->maxLength(150), TextInput::make('grade_code')->label('Golongan ruang')->required()->maxLength(30)];

        return [...$fields,
            DatePicker::make('tmt_start')->label('TMT awal (wajib dari sumber nyata)')->required(),
            DatePicker::make('tmt_end')->label('Tanggal akhir')->afterOrEqual('tmt_start'),
            TextInput::make('sk_number')->label('Nomor SK')->maxLength(150), DatePicker::make('sk_date')->label('Tanggal SK'),
            Textarea::make('source_ref')->label('Referensi dokumen yang diverifikasi')->required()->maxLength(255),
        ];
    }
}
