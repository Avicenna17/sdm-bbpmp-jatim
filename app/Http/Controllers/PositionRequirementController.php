<?php

namespace App\Http\Controllers;

use App\Models\ReportingPeriod;
use Illuminate\Http\Request;

class PositionRequirementController extends Controller
{
    public function __invoke(Request $request)
    {
        $positionName = $request->query('jabatan');
        
        // Bersihkan nama jabatan jika kosong
        if (blank($positionName)) {
            $positionName = 'Jabatan Fungsional';
        }

        // Ambil periode rilis untuk header dashboard
        $period = ReportingPeriod::where('status', 'published')
            ->orderByDesc('period_month')
            ->first(['id', 'period_month', 'published_at', 'status']);

        // Data 4 kategori persyaratan jabatan fungsional
        $categories = [
            [
                'id' => 'pengangkatan-pertama',
                'number' => '01',
                'title' => 'Pengangkatan Pertama',
                'badge' => 'Jalur CPNS / Awal',
                'type_label' => 'Tautan / File Dokumen',
                'description' => 'Persyaratan kelengkapan berkas bagi pegawai yang pertama kali diangkat ke dalam jabatan fungsional melalui formasi pengadaan.',
                'url' => '{link_google_drive}',
                'icon' => 'first_appointment',
            ],
            [
                'id' => 'perpindahan-jabatan',
                'number' => '02',
                'title' => 'Perpindahan Jabatan',
                'badge' => 'Alih Jalur Jabatan',
                'type_label' => 'Tautan / File Dokumen',
                'description' => 'Persyaratan bagi PNS yang berpindah dari jabatan administrasi, pelaksana, atau perpindahan antar-jabatan fungsional.',
                'url' => '{link_google_drive}',
                'icon' => 'transfer',
            ],
            [
                'id' => 'penyesuaian-inpassing',
                'number' => '03',
                'title' => 'Penyesuaian / Inpassing',
                'badge' => 'Penyetaraan Jabatan',
                'type_label' => 'Tautan / File Dokumen',
                'description' => 'Persyaratan pengangkatan melalui mekanisme penyesuaian (inpassing) sesuai dengan formasi dan kebijakan kementerian.',
                'url' => '{link_google_drive}',
                'icon' => 'inpassing',
            ],
            [
                'id' => 'promosi',
                'number' => '04',
                'title' => 'Promosi',
                'badge' => 'Kenaikan Jenjang',
                'type_label' => 'Tautan / File Dokumen',
                'description' => 'Persyaratan kenaikan jenjang jabatan fungsional setingkat lebih tinggi atau promosi atas dasar pencapaian prestasi kerja.',
                'url' => '{link_google_drive}',
                'icon' => 'promotion',
            ],
        ];

        return response()->view('public-dashboard.position-requirements', compact('positionName', 'period', 'categories'))
            ->header('Cache-Control', 'no-store');
    }
}

