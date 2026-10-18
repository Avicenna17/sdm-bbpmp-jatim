<?php

namespace App\Filament\Pak\Pages;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\Queries\PakCaseQuery;
use App\Filament\Pak\Resources\EmployeeResource;
use Filament\Pages\Page;

class Dashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Beranda PAK';

    protected static ?string $title = 'Beranda PAK Konversi';

    protected ?string $subheading = 'Kelola data referensi, kelengkapan pegawai, dan proses penyusunan PAK Konversi.';

    protected static string $view = 'filament.pak.dashboard';

    protected static ?string $slug = 'pak';

    protected function getViewData(): array
    {
        $employeeAccess = EmployeeResource::canViewAny();
        $caseAccess = Access::allows(auth()->user(), 'pak.case.view');

        return ['metrics' => [
            ['label' => 'Data Pegawai', 'value' => $employeeAccess ? EmployeeResource::getEloquentQuery()->count() : '—', 'description' => $employeeAccess ? 'Data pada daftar PAK; bukan ukuran verifikasi atau kelengkapan.' : 'Tidak memiliki akses'],
            ['label' => 'Draf PAK', 'value' => $caseAccess ? app(PakCaseQuery::class)->forManager(auth()->user())->where('status', 'DRAFT')->count() : '—', 'description' => $caseAccess ? 'Jumlah draf PAK tersimpan.' : 'Tidak memiliki akses'],
            ['label' => 'Perlu Pemeriksaan', 'value' => '—', 'description' => 'Belum tersedia pada Phase 01.'],
            ['label' => 'PAK Ditetapkan', 'value' => '—', 'description' => 'Belum tersedia pada Phase 01.'],
        ]];
    }

    public static function canAccess(): bool
    {
        return Access::allows(auth()->user(), 'pak.access');
    }
}
