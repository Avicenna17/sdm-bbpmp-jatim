<?php

namespace App\Filament\Auth;

use App\Filament\Support\InternalIntendedUrl;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

final class InternalLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect(app(InternalIntendedUrl::class)->destination($request, $request->user()));
    }
}
