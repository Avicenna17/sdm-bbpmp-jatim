<?php

namespace App\Filament\Pak\Resources;

use App\Filament\Pak\Support\MasterResource;
use App\Models\FunctionalPosition;

class FunctionalPositionResource extends MasterResource
{
    protected static ?string $slug = 'pak/functional-positions';

    protected static ?string $model = FunctionalPosition::class;

    protected static ?string $navigationLabel = 'Jabatan Fungsional';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Jabatan Fungsional';

    protected static ?string $pluralModelLabel = 'Jabatan Fungsional';

    public static string $masterType = 'position';

    public static function getPages(): array
    {
        return ['index' => FunctionalPositionResource\Pages\ManageFunctionalPosition::route('/')];
    }
}
