<?php

namespace App\Filament\Pak\Resources;

use App\Filament\Pak\Support\MasterResource;
use App\Models\FunctionalPositionLevel;

class FunctionalPositionLevelResource extends MasterResource
{
    protected static ?string $slug = 'pak/functional-position-levels';

    protected static ?string $model = FunctionalPositionLevel::class;

    protected static ?string $navigationLabel = 'Pemetaan Jabatan dan Jenjang';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Pemetaan Jabatan dan Jenjang';

    protected static ?string $pluralModelLabel = 'Pemetaan Jabatan dan Jenjang';

    public static string $masterType = 'mapping';

    public static function getPages(): array
    {
        return ['index' => FunctionalPositionLevelResource\Pages\ManageFunctionalPositionLevel::route('/')];
    }
}
