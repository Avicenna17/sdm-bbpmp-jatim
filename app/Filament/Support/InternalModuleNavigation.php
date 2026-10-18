<?php

namespace App\Filament\Support;

use App\Filament\Pages\ModuleSelector;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Livewire\Livewire;

final class InternalModuleNavigation
{
    public function currentModule(): ?string
    {
        $access = app(InternalModuleAccess::class);
        // Livewire resolves each verified component before rendering it. No request-global snapshot heuristic.
        if ($component = Livewire::current()) {
            if ($module = $access->moduleForComponent($component)) {
                return $module;
            }
        }
        if (Livewire::isLivewireRequest()) {
            return null;
        }

        return $access->entryForRoute(request()->route())['module'] ?? null;
    }

    public function build(): NavigationBuilder
    {
        $builder = new NavigationBuilder;
        $access = app(InternalModuleAccess::class);
        $modules = $access->accessible(auth()->user());
        $module = $this->currentModule();
        if (count($modules) > 1) {
            $builder->item(NavigationItem::make('Ganti Modul')->icon('heroicon-o-squares-2x2')->url(ModuleSelector::getUrl(panel: 'admin')));
        }
        if (! $module || ! isset($modules[$module])) {
            return $builder;
        }
        $definition = $modules[$module];
        $groups = [];
        foreach ([...$definition['pages'], ...$definition['resources']] as $component) {
            if (! $component::shouldRegisterNavigation() || ! $component::canAccess()) {
                continue;
            }
            foreach ($component::getNavigationItems() as $item) {
                $groups[$item->getGroup() ?? ''][] = $item;
            }
        }
        foreach (array_unique(['', ...$definition['groups'], ...array_keys($groups)]) as $group) {
            $items = $groups[$group] ?? [];
            usort($items, fn ($a, $b) => $a->getSort() <=> $b->getSort());
            if ($group === '') {
                $builder->items($items);
            } elseif ($items) {
                $builder->group($group, $items);
            }
        }

        return $builder;
    }
}
