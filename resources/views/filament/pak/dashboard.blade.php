<x-filament-panels::page>
    <style>
        .pak-summary-grid{display:grid;gap:.75rem;grid-template-columns:1fr}
        @media(min-width:640px){.pak-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .pak-summary-card,.pak-preparation{padding:1.25rem;border:1px solid #e4e4e7;border-radius:.75rem;background:#fff}
        .pak-summary-heading{display:flex;justify-content:space-between;align-items:center;gap:1rem;color:#62626b;font-size:.875rem}
        .pak-summary-heading svg{color:#92929b}
        .pak-summary-value{font-size:1.75rem;font-weight:600;margin:.5rem 0}
        .pak-summary-description{font-size:.8125rem;line-height:1.5;color:#62626b}
        .pak-preparation h2{font-size:1.1rem;font-weight:600;margin-bottom:.75rem}
        .pak-preparation p{color:#62626b;line-height:1.6}
        .pak-preparation-note{display:flex;gap:.65rem;margin-top:.75rem;align-items:flex-start}
        .pak-preparation-note svg{flex-shrink:0;margin-top:.2rem}
        .dark .pak-summary-card,.dark .pak-preparation{background:#18181b;border-color:#3f3f46}
        .dark .pak-summary-heading,.dark .pak-summary-description,.dark .pak-preparation p{color:#a1a1aa}
    </style>
    <div class="pak-summary-grid">
        @foreach ($metrics as $metric)
            <section class="pak-summary-card" aria-label="{{ $metric['label'] }}">
                <div class="pak-summary-heading">
                    <h2>{{ $metric['label'] }}</h2>
                    <x-filament::icon :icon="['heroicon-o-users', 'heroicon-o-pencil-square', 'heroicon-o-clipboard-document-check', 'heroicon-o-document-check'][$loop->index]" class="h-5 w-5" aria-hidden="true" />
                </div>
                <p class="pak-summary-value">{{ $metric['value'] }}</p>
                <p class="pak-summary-description">{{ $metric['description'] }}</p>
            </section>
        @endforeach
    </div>
    <section class="pak-preparation">
        <h2>Persiapan Data PAK</h2>
        <p>Pastikan data pegawai, riwayat kepegawaian, dan referensi jabatan fungsional telah tersedia sebelum penyusunan PAK.</p>
        <p class="pak-preparation-note"><x-filament::icon icon="heroicon-o-information-circle" class="h-5 w-5" aria-hidden="true" /><span>Draf PAK belum merupakan hasil perhitungan atau keputusan kepegawaian.</span></p>
    </section>
</x-filament-panels::page>
