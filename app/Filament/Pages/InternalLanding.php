<?php

namespace App\Filament\Pages;

use App\Filament\Support\InternalModuleAccess;
use Filament\Pages\Page;

class InternalLanding extends Page
{
    protected static ?string $slug = '/';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.internal.landing';

    public static function canAccess(): bool
    {
        return (bool) app(InternalModuleAccess::class)->accessible(auth()->user());
    }

    public function mount(): void
    {
        $this->redirect(app(InternalModuleAccess::class)->landing(auth()->user()));
    }
}
