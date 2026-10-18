<?php

namespace App\Filament\Support;

use App\Filament\Pages;
use App\Filament\Pak\Pages\Dashboard;
use App\Filament\Pak\Resources as Pak;
use App\Filament\Resources as Sdm;
use App\Models\User;
use Illuminate\Routing\Route;

final class InternalModuleAccess
{
    public function definitions(): array
    {
        return [
            'sdm' => [
                'label' => 'SDM', 'permission' => 'period.view', 'home' => Pages\ManageData::class,
                'pages' => [Pages\PersonnelData::class, Pages\PositionData::class, Pages\ManageData::class, Pages\ImportHistory::class, Pages\ExportData::class, Pages\Templates::class],
                'resources' => [Sdm\UserResource::class, Sdm\RoleResource::class],
                'groups' => ['Data Master', 'Pengelolaan Data', 'Manajemen Pengguna'],
            ],
            'pak' => [
                'label' => 'PAK Konversi', 'permission' => 'pak.access', 'home' => Dashboard::class,
                'pages' => [Dashboard::class],
                'resources' => [Pak\EmployeeResource::class, Pak\FunctionalCategoryResource::class, Pak\FunctionalLevelResource::class, Pak\FunctionalPositionResource::class, Pak\FunctionalPositionLevelResource::class, Pak\PakRuleSetResource::class, Pak\PakCaseResource::class, Pak\PakEventResource::class],
                'groups' => ['Pegawai', 'Master PAK', 'Draft PAK', 'Audit'],
            ],
        ];
    }

    public function accessible(?User $user): array
    {
        if (! $user || ! $user->fresh()?->is_active) {
            return [];
        }

        return array_filter($this->definitions(), fn ($module) => $user->can($module['permission']));
    }

    public function allows(?User $user, string $module): bool
    {
        return isset($this->accessible($user)[$module]);
    }

    public function home(string $module): string
    {
        return $this->definitions()[$module]['home']::getUrl(panel: 'admin');
    }

    public function landing(User $user): string
    {
        $modules = $this->accessible($user);
        abort_if($modules === [], 403);

        return count($modules) === 1 ? $this->home(array_key_first($modules)) : Pages\ModuleSelector::getUrl(panel: 'admin');
    }

    /** Explicit registry: unclassified components/routes never inherit access. */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->definitions() as $module => $definition) {
            foreach ($definition['pages'] as $page) {
                $entries[$page::getRouteName('admin')] = ['module' => $module, 'component' => $page, 'resource' => null, 'operation' => 'page'];
            }
            foreach ($definition['resources'] as $resource) {
                foreach ($resource::getPages() as $operation => $registration) {
                    $entries[$resource::getRouteBaseName('admin').'.'.$operation] = ['module' => $module, 'component' => $registration->getPage(), 'resource' => $resource, 'operation' => $operation];
                }
            }
        }
        foreach ([Pages\InternalLanding::class, Pages\ModuleSelector::class] as $page) {
            $entries[$page::getRouteName('admin')] = ['module' => null, 'component' => $page, 'resource' => null, 'operation' => 'neutral'];
        }

        return $entries;
    }

    public function entryForRoute(?Route $route): ?array
    {
        return $route ? ($this->entries()[$route->getName()] ?? null) : null;
    }

    public function moduleForComponent(object $component): ?string
    {
        foreach ($this->entries() as $entry) {
            if ($entry['component'] === $component::class) {
                return $entry['module'];
            }
        }

        return null;
    }
}
