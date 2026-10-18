<x-filament-panels::page>
    <p>Pilih modul untuk mulai bekerja. Menu akan mengikuti modul yang Anda buka.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,18rem),1fr));gap:1.5rem">
        @foreach ($modules as $module)
            <x-filament::section>
                <x-slot name="heading">{{ $module['label'] }}</x-slot>
                <p style="margin-bottom:1rem">{{ $module['id'] === 'sdm' ? 'Data pegawai, peta jabatan, dan pengelolaan data SDM.' : 'Profil dan histori pegawai, master JF, serta draft PAK.' }}</p>
                <x-filament::button tag="a" :href="$module['url']">Buka {{ $module['label'] }}</x-filament::button>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
