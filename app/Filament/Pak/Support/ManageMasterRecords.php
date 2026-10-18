<?php

namespace App\Filament\Pak\Support;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

abstract class ManageMasterRecords extends ManageRecords
{
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah')->using(fn (array $data) => static::getResource()::saveRecord($data))];
    }
}
