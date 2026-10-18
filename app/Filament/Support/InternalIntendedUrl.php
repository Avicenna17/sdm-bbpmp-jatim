<?php

namespace App\Filament\Support;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class InternalIntendedUrl
{
    public function destination(Request $request, User $user): string
    {
        $access = app(InternalModuleAccess::class);
        $fallback = $access->landing($user);
        $intended = session()->pull('url.intended');
        if (! is_string($intended) || preg_match('/[\x00-\x20\\\\]/', $intended)) {
            return $fallback;
        }
        $origin = $request->getSchemeAndHttpHost();
        if (str_starts_with($intended, '/') && ! str_starts_with($intended, '//')) {
            $intended = $origin.$intended;
        }
        $parts = parse_url($intended);
        $expected = parse_url($origin);
        $scheme = strtolower($parts['scheme'] ?? '');
        $expectedScheme = strtolower($expected['scheme'] ?? '');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $expectedPort = $expected['port'] ?? ($expectedScheme === 'https' ? 443 : 80);
        if (! $parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || $scheme !== $expectedScheme
            || strtolower($parts['host'] ?? '') !== strtolower($expected['host'] ?? '')
            || $port !== $expectedPort) {
            return $fallback;
        }
        try {
            $destination = Request::create($intended, 'GET', server: $request->server->all());
            $route = app('router')->getRoutes()->match($destination);
            $entry = $access->entryForRoute($route);
            if (! $entry || ! $entry['module'] || ! $access->allows($user, $entry['module'])) {
                return $fallback;
            }
            if (! $entry['resource']) {
                return $entry['component']::canAccess() ? $intended : $fallback;
            }
            $resource = $entry['resource'];
            if (! $resource::canAccess()) {
                return $fallback;
            }
            $allowed = match ($entry['operation']) {
                'index' => $resource::canViewAny(),
                'create' => $resource::canCreate(),
                'view', 'edit' => $this->allowsRecord($resource, $entry['operation'], $route->parameter('record')),
                default => false,
            };

            return $allowed ? $intended : $fallback;
        } catch (AuthorizationException|ModelNotFoundException $e) {
            return $fallback;
        } catch (HttpExceptionInterface $e) {
            if (! in_array($e->getStatusCode(), [403, 404, 405], true)) {
                throw $e;
            }

            return $fallback;
        }
    }

    private function allowsRecord(string $resource, string $operation, mixed $key): bool
    {
        if (! is_string($key) && ! is_int($key)) {
            return false;
        }
        $record = $resource::resolveRecordRouteBinding($key);

        return $record && ($operation === 'view' ? $resource::canView($record) : $resource::canEdit($record));
    }
}
