<?php

namespace App\Filament\Pak\Resources;

use App\Filament\Pak\Support\MasterResource;
use App\Models\FunctionalLevel;

class FunctionalLevelResource extends MasterResource
{
    protected static ?string $slug = 'pak/functional-levels';

    protected static ?string $model = FunctionalLevel::class;

    protected static ?string $navigationLabel = 'Jenjang JF';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Jenjang JF';

    protected static ?string $pluralModelLabel = 'Jenjang JF';

    public static string $masterType = 'level';

    public static function getPages(): array
    {
        return ['index' => FunctionalLevelResource\Pages\ManageFunctionalLevel::route('/')];
    }
}
