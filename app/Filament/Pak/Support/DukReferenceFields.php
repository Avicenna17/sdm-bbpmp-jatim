<?php

namespace App\Filament\Pak\Support;

use App\Domain\Pak\Queries\EmployeeReferenceQuery;
use App\Models\Person;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;

final class DukReferenceFields
{
    public static function fields(bool $prefill = false): array
    {
        return [
            DatePicker::make('reference_as_of')->label('Tanggal acuan referensi DUK')->default(now()->format('Y-m-d'))->required()->live()->afterStateUpdated(fn (Set $set) => $set('duk_snapshot_id', null))->dehydrated(false),
            TextInput::make('reference_page')->label('Halaman arsip (20 referensi per halaman)')->numeric()->default(1)->minValue(1)->live()->dehydrated(false),
            Select::make('duk_snapshot_id')->label('DUK published (opsional)')->live()->options(function (Get $get, ?Person $record) {
                if (! $record || ! $get('reference_as_of')) {
                    return [];
                }
                $rows = app(EmployeeReferenceQuery::class)->search(auth()->user(), $record->id, $get('reference_as_of'), '', (int) ($get('reference_page') ?: 1));

                return collect($rows->items())->mapWithKeys(fn ($r) => [$r['id'] => $r['period_month'].' · '.$r['name_at_period']])->all();
            })->afterStateUpdated(function ($state, Get $get, Set $set, ?Person $record) use ($prefill) {
                if (! $state || ! $record || ! $prefill) {
                    return;
                }
                $r = app(EmployeeReferenceQuery::class)->find(auth()->user(), (int) $state, $record->id, $get('reference_as_of'))->toArray();
                foreach (['rank_name', 'grade_code', 'position_name'] as $field) {
                    $set($field, $r[$field]);
                }
                // No TMT, JF, level, SK or organizational identity is inferred.
            })->dehydrated(false),
            Placeholder::make('duk_preview')->label('Kandidat dari DUK')->content(function (Get $get, ?Person $record) {
                if (! $get('duk_snapshot_id') || ! $record) {
                    return 'Pilih referensi published. Data ini belum merupakan histori terverifikasi.';
                }
                $r = app(EmployeeReferenceQuery::class)->find(auth()->user(), (int) $get('duk_snapshot_id'), $record->id, $get('reference_as_of'))->toArray();

                return view('filament.pak.duk-reference', ['reference' => $r]);
            }),
        ];
    }
}
