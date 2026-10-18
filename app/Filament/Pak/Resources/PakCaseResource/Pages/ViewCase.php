<?php

namespace App\Filament\Pak\Resources\PakCaseResource\Pages;

use App\Filament\Pak\Resources\PakCaseResource;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewCase extends ViewRecord
{
    protected static string $resource = PakCaseResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        $fields = ['name' => 'Nama snapshot', 'nip' => 'NIP snapshot', 'rank_name' => 'Pangkat', 'grade_code' => 'Golongan', 'rank_tmt' => 'TMT pangkat', 'position_name' => 'Jabatan utama', 'position_tmt' => 'TMT jabatan', 'functional_position_name' => 'JF', 'functional_level_name' => 'Jenjang', 'institution_name' => 'Instansi historis', 'work_unit' => 'Unit historis'];

        return $infolist->schema([
            Section::make('Draft pemeriksaan')->schema([TextEntry::make('purpose_type')->label('Tujuan'), TextEntry::make('period_start')->date(), TextEntry::make('period_end')->date(), TextEntry::make('employee_data_as_of')->label('Tanggal acuan')->date(), TextEntry::make('status')->badge(), TextEntry::make('notes')->label('Catatan')])->columns(2),
            Section::make('Snapshot tersimpan')->schema(array_map(fn ($key, $label) => TextEntry::make('employeeSnapshot.'.$key)->label($label)->placeholder('Belum tersedia'), array_keys($fields), array_values($fields)))->columns(2),
            TextEntry::make('employeeSnapshot.snapshot_payload.identity_source')->label('Asal identitas'),
            TextEntry::make('employeeSnapshot.snapshot_payload.missing_fields')->label('Data belum tersedia')->listWithLineBreaks(),
        ]);
    }
}
