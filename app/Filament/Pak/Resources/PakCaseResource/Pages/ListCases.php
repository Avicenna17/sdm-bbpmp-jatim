<?php

namespace App\Filament\Pak\Resources\PakCaseResource\Pages;

use App\Filament\Pak\Resources\PakCaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCases extends ListRecords
{
    protected static string $resource = PakCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
