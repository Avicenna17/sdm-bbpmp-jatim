<?php

namespace App\Filament\Pak\Resources\PakCaseResource\Pages;

use App\Domain\Pak\Services\PakCaseService;
use App\Filament\Pak\Resources\PakCaseResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCase extends CreateRecord
{
    protected static string $resource = PakCaseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(PakCaseService::class)->create(auth()->user(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
