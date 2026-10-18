<?php

namespace App\Http\Middleware;

use App\Filament\Support\InternalModuleAccess;
use Closure;
use Illuminate\Http\Request;

final class EnsureInternalModuleAccess
{
    public function handle(Request $request, Closure $next)
    {
        $access = app(InternalModuleAccess::class);
        abort_unless($access->accessible($request->user()), 403);
        // Logout and Filament's home dispatcher are the only non-page authenticated routes.
        if (in_array($request->route()?->getName(), ['filament.admin.auth.logout', 'filament.admin.home'], true)) {
            return $next($request);
        }
        $entry = $access->entryForRoute($request->route());
        abort_unless($entry, 403);
        if ($entry['module']) {
            abort_unless($access->allows($request->user(), $entry['module']), 403);
        }

        return $next($request);
    }
}
