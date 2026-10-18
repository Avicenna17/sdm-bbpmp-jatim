<?php

namespace App\Filament\Auth;

use App\Filament\Support\InternalIntendedUrl;
use App\Filament\Support\InternalModuleAccess;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;

class Login extends \Filament\Pages\Auth\Login
{
    public function mount(): void
    {
        $guard = Filament::auth();
        if ($guard->check()) {
            if (app(InternalModuleAccess::class)->accessible($guard->user())) {
                $this->redirect(app(InternalIntendedUrl::class)->destination(request(), $guard->user()));

                return;
            }
            $guard->logout();
            session()->invalidate();
            session()->regenerateToken();
        }
        parent::mount();
    }

    public function authenticate(): ?LoginResponse
    {
        return parent::authenticate() ? app(InternalLoginResponse::class) : null;
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        return parent::getCredentialsFromFormData($data) + ['is_active' => true];
    }

    protected static string $view = 'filament.auth.login';
}
