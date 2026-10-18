<?php

namespace App\Filament\Pak\Pages;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use Filament\Pages\Page;

class Dashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Beranda PAK';

    protected static ?string $title = 'Fondasi PAK';

    protected static string $view = 'filament.pak.dashboard';

    protected static ?string $slug = 'pak';

    public static function canAccess(): bool
    {
        return Access::allows(auth()->user(), 'pak.access');
    }
}
