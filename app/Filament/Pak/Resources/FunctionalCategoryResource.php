<?php

namespace App\Filament\Pak\Resources;

use App\Filament\Pak\Support\MasterResource;
use App\Models\FunctionalCategory;

class FunctionalCategoryResource extends MasterResource
{
    protected static ?string $slug = 'pak/functional-categories';

    protected static ?string $model = FunctionalCategory::class;

    protected static ?string $navigationLabel = 'Kategori JF';

    protected static ?string $modelLabel = 'Kategori JF';

    public static string $masterType = 'category';

    public static function getPages(): array
    {
        return ['index' => FunctionalCategoryResource\Pages\ManageFunctionalCategory::route('/')];
    }
}
