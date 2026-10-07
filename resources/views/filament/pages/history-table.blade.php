<x-filament-panels::page>
    @php($counts = $this->sourceCounts())
    <x-filament::tabs label="Jenis data pada histori import">
        @foreach(['' => ['Semua', 'heroicon-o-inbox-stack'], 'PERSONNEL_DUK' => ['DUK Pegawai', 'heroicon-o-users'], 'POSITION_REQUIREMENT' => ['Peta Jabatan / Kebutuhan', 'heroicon-o-building-office-2']] as $key => [$label, $icon])
            <x-filament::tabs.item :active="$sourceFilter === $key" :icon="$icon" :badge="$counts[$key]" wire:click="selectSourceFilter('{{ $key }}')">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
    {{ $this->table }}
</x-filament-panels::page>
