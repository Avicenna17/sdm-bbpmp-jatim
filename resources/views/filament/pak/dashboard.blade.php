<x-filament-panels::page>
    <x-filament::section heading="Persiapan data PAK">
        <p>Kelola profil, histori terverifikasi, master jabatan fungsional, dan draft pemeriksaan.</p>
        <p>Draft belum merupakan hasil perhitungan atau keputusan kepegawaian.</p>
        @can('pak.case.view')
            <p class="mt-4">Draft tersimpan: {{ \App\Models\PakCase::where('status', 'DRAFT')->count() }}</p>
        @endcan
    </x-filament::section>
</x-filament-panels::page>
