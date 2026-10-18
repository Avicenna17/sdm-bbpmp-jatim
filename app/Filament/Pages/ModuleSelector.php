<?php

namespace App\Filament\Pages;

use App\Filament\Support\InternalModuleAccess;
use Filament\Pages\Page;

class ModuleSelector extends Page
{
    protected static ?string $slug = 'modules';

    protected static ?string $title = 'Modul Aplikasi';

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected function getLayoutData(): array
    {
        return ['maxWidth' => '6xl', 'hasTopbar' => false];
    }

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.internal.modules';

    public static function canAccess(): bool
    {
        return (bool) app(InternalModuleAccess::class)->accessible(auth()->user());
    }

    public function mount(): void
    {
        $access = app(InternalModuleAccess::class);
        if (count($access->accessible(auth()->user())) < 2) {
            $this->redirect($access->landing(auth()->user()));
        }
    }

    protected function getViewData(): array
    {
        $access = app(InternalModuleAccess::class);
        $modules = [];
        foreach ($access->accessible(auth()->user()) as $id => $definition) {
            $modules[] = [
                'id' => $id,
                'label' => $id === 'sdm' ? 'Manajemen SDM' : 'PAK Konversi',
                'icon' => $id === 'sdm' ? 'heroicon-o-users' : 'heroicon-o-document-text',
                'description' => $id === 'sdm' ? 'Data pegawai, riwayat, DUK, dan peta jabatan.' : 'Konversi kinerja, akumulasi AK, dan penetapan PAK.',
                'url' => $access->home($id),
            ];
        }

        return ['modules' => $modules];
    }
}
