<?php

namespace App\Filament\Pak\Resources\PakRuleSetResource\Pages;

use App\Domain\Pak\Services\PakRuleSetService;
use App\Filament\Pak\Resources\PakRuleSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRuleSets extends ManageRecords
{
    protected static string $resource = PakRuleSetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => app(PakRuleSetService::class)->save(auth()->user(), $data))];
    }
}
