<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use EragLaravelPwa\EragLaravelPwaServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    EragLaravelPwaServiceProvider::class,
];
