<x-filament-panels::page>
    <a href="{{ $caseUrl }}" class="text-primary-600 underline">Kembali ke case</a>
    <p>Riwayat perhitungan tersimpan — hanya baca. Status pemeriksaan bukan persetujuan AK.</p>
    <nav aria-label="Revisi konversi" class="flex flex-wrap gap-3">
        @foreach ($revisions as $revision)
            <a href="{{ $revision['url'] }}" @if ($revision['selected']) aria-current="page" @endif class="text-primary-600 underline">{{ $revision['label'] }}</a>
        @endforeach
    </nav>
    @if ($saved === null)
        <p>Belum ada run konversi tersimpan.</p>
    @else
        <x-filament::section heading="Run tersimpan">
            <dl class="grid gap-3 sm:grid-cols-2">
                @foreach ($saved['summary'] as $label => $value)
                    <div><dt class="font-medium">{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
            <p>Total eksak: {{ $saved['total']['exact'] }} · Tampilan total: {{ $saved['total']['human'] }}</p>
            @foreach ($saved['rule'] as $label => $value)<p>{{ $label }}: {{ $value }}</p>@endforeach
            @foreach ($saved['issues'] as $issue)
                <p>@foreach ($issue as $label => $value){{ $label }}: {{ $value }} @endforeach</p>
            @endforeach
        </x-filament::section>
        <nav aria-label="Rantai revisi" class="flex gap-3">
            @foreach ($saved['chain'] as $link)<a href="{{ $link['url'] }}" class="text-primary-600 underline">{{ $link['label'] }}</a>@endforeach
        </nav>
        @forelse ($saved['periods'] as $period)
            <x-filament::section heading="Baris perhitungan tersimpan">
                <dl class="grid gap-3 sm:grid-cols-2">
                    @foreach ($period['summary'] as $label => $value)<div><dt class="font-medium">{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
                </dl>
                <p>Hasil eksak: {{ $period['amount']['exact'] }} · Tampilan hasil: {{ $period['amount']['human'] }}</p>
                @forelse ($period['issues'] as $issue)
                    <p>@foreach ($issue as $label => $value){{ $label }}: {{ $value }} @endforeach</p>
                @empty
                    <p>Tidak ada issue tersimpan pada baris ini.</p>
                @endforelse
                @foreach ($period['provenance'] as $heading => $fields)
                    @if ($fields !== [])
                        <h3 class="mt-4 font-semibold">{{ $heading }}</h3>
                        @foreach ($fields as $label => $value)<p>{{ $label }}: {{ $value }}</p>@endforeach
                    @endif
                @endforeach
            </x-filament::section>
        @empty
            <p>Tidak ada baris perhitungan tersimpan.</p>
        @endforelse
    @endif
</x-filament-panels::page>
