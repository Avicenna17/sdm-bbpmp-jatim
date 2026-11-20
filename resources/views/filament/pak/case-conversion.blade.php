<x-filament-panels::page>
    @if ($canCalculate)
        <x-filament::section heading="Pratinjau konversi">
            <p>Pratinjau belum disimpan. Hasil dan status ditentukan oleh perhitungan server.</p>
            <form wire:submit="previewConversion" class="space-y-3">
                <label class="block">Awal permintaan <input type="date" wire:model.live="requestedStart" class="rounded border-gray-300 dark:bg-gray-900"></label>
                <label class="block">Akhir permintaan <input type="date" wire:model.live="requestedEnd" class="rounded border-gray-300 dark:bg-gray-900"></label>
                <x-filament::button type="submit" wire:loading.attr="disabled">Pratinjau</x-filament::button>
            </form>
            @foreach (['conversion', 'case', 'snapshot', 'requested_start', 'requested_end', 'requested_range'] as $field)
                @error($field)<p role="alert" class="text-danger-600">{{ $message }}</p>@enderror
            @endforeach
            @if ($previewDisplay !== [])
                <p>{{ $previewDisplay['start'] }} — {{ $previewDisplay['end'] }}</p>
                <p>Total eksak pratinjau: {{ $previewDisplay['exact'] }} · Tampilan total pratinjau: {{ $previewDisplay['human'] }}</p>
                <p>{{ $previewDisplay['status'] === null ? 'Pratinjau ini tidak dapat disimpan.' : 'Status jika disimpan: '.$previewDisplay['status'] }}</p>
                @foreach ($previewDisplay['issues'] as $issue)
                    <p>@foreach ($issue as $label => $value){{ $label }}: {{ $value }} @endforeach</p>
                @endforeach
                @foreach ($previewDisplay['lines'] as $line)
                    <div class="mt-4 border-t pt-3">
                        <p>Bulan: {{ implode(', ', $line['months']) }}</p>
                        <p>Hasil eksak pratinjau: {{ $line['exact'] }} · Tampilan hasil pratinjau: {{ $line['human'] }}</p>
                        @foreach ($line['issues'] as $issue)<p>@foreach ($issue as $label => $value){{ $label }}: {{ $value }} @endforeach</p>@endforeach
                        @foreach (['source' => 'Sumber', 'position' => 'Posisi', 'rule' => 'Aturan'] as $key => $heading)
                            @if ($line[$key] !== [])<h3 class="font-medium">{{ $heading }}</h3>@endif
                            @foreach ($line[$key] as $label => $value)<p>{{ $label }}: {{ $value }}</p>@endforeach
                        @endforeach
                    </div>
                @endforeach
                @if ($canInitialSave && $expectedCaseLockVersion !== null)
                    <x-filament::button wire:click="saveInitialConversion" wire:loading.attr="disabled">Simpan konversi awal</x-filament::button>
                @elseif (! $canInitialSave)
                    <p>Penyimpanan awal hanya tersedia untuk draf tanpa riwayat konversi.</p>
                @endif
            @endif
        </x-filament::section>
    @endif
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
        @if ($canCalculate)
            <x-filament::section heading="Revisi perhitungan">
                @if ($canReplace)
                    <p>Rentang revisi mengikuti run tersimpan dan tidak dapat diubah.</p>
                    <x-filament::button wire:click="previewReplacement" wire:loading.attr="disabled">Pratinjau revisi</x-filament::button>
                @endif
                @error('replacement')<p role="alert" class="text-danger-600">{{ $message }}</p>@enderror
                @if ($replacementPreviewDisplay !== [])
                    <p>{{ $replacementPreviewDisplay['start'] }} — {{ $replacementPreviewDisplay['end'] }}</p>
                    <p>Total eksak revisi: {{ $replacementPreviewDisplay['exact'] }} · Tampilan total revisi: {{ $replacementPreviewDisplay['human'] }}</p>
                    <p>{{ $replacementPreviewDisplay['status'] === null ? 'Pratinjau revisi ini tidak dapat disimpan.' : 'Status jika dibuat: '.$replacementPreviewDisplay['status'] }}</p>
                    @if (! $replacementPreviewDisplay['has_change'])<p>Tidak ada perubahan perhitungan atau dependensi yang memerlukan revisi.</p>@endif
                    @foreach ($replacementPreviewDisplay['issues'] as $issue)<p>@foreach ($issue as $label => $value){{ $label }}: {{ $value }} @endforeach</p>@endforeach
                    @foreach ($replacementPreviewDisplay['lines'] as $line)
                        <div class="mt-3 border-t pt-3">
                            <p>Bulan: {{ implode(', ', $line['months']) }}</p>
                            <p>Hasil eksak revisi: {{ $line['exact'] }} · Tampilan hasil revisi: {{ $line['human'] }}</p>
                            @foreach ($line['issues'] as $issue)<p>@foreach ($issue as $label => $value){{ $label }}: {{ $value }} @endforeach</p>@endforeach
                            @foreach (['source' => 'Sumber', 'position' => 'Posisi', 'rule' => 'Aturan'] as $key => $heading)
                                @if ($line[$key] !== [])<h3 class="font-medium">{{ $heading }}</h3>@endif
                                @foreach ($line[$key] as $label => $value)<p>{{ $label }}: {{ $value }}</p>@endforeach
                            @endforeach
                        </div>
                    @endforeach
                    @if ($canReplace && $replacementPredecessorRunId !== null)
                        <form wire:submit="replaceConversion" class="mt-3 space-y-3">
                            <label class="block">Alasan revisi <textarea wire:model="revisionReason" class="block w-full rounded border-gray-300 dark:bg-gray-900"></textarea></label>
                            <x-filament::button type="submit" wire:loading.attr="disabled">Buat revisi</x-filament::button>
                        </form>
                    @endif
                @endif
            </x-filament::section>
        @endif
        @if ($comparison !== [])
            <x-filament::section heading="Perbandingan revisi">
                <p>Kedua sisi menampilkan bukti tersimpan masing-masing. Nomor baris antar revisi tidak menyatakan kesetaraan perhitungan.</p>
                <div class="grid gap-6 lg:grid-cols-2">
                    @foreach ($comparison as $heading => $side)
                        <div>
                            <h3 class="font-semibold">{{ $heading }}</h3>
                            @foreach ($side['summary'] as $label => $value)<p>{{ $label }}: {{ $value }}</p>@endforeach
                            <p>Total eksak: {{ $side['total']['exact'] }} · Tampilan total: {{ $side['total']['human'] }}</p>
                            @foreach ($side['issues'] as $issue)<p>@foreach ($issue as $label => $value){{ $label }}: {{ $value }} @endforeach</p>@endforeach
                            @foreach ($side['rule'] as $label => $value)<p>{{ $label }}: {{ $value }}</p>@endforeach
                            @foreach ($side['periods'] as $period)
                                <div class="mt-3 border-t pt-3">
                                    @foreach ($period['summary'] as $label => $value)<p>{{ $label }}: {{ $value }}</p>@endforeach
                                    <p>Hasil eksak: {{ $period['amount']['exact'] }} · Tampilan hasil: {{ $period['amount']['human'] }}</p>
                                    @foreach ($period['issues'] as $issue)<p>@foreach ($issue as $label => $value){{ $label }}: {{ $value }} @endforeach</p>@endforeach
                                    @foreach ($period['provenance'] as $label => $fields)
                                        @if ($fields !== [])<h4 class="font-medium">{{ $label }}</h4>@endif
                                        @foreach ($fields as $key => $value)<p>{{ $key }}: {{ $value }}</p>@endforeach
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
        @if ($canCalculate)
            <x-filament::section heading="Asesmen dan tinjauan eksplisit">
                <p>Asesmen memeriksa perubahan tanpa mengubah perhitungan tersimpan. Tinjauan mencatat hasil pemeriksaan, bukan persetujuan AK.</p>
                <x-filament::button wire:click="assessConversion" wire:loading.attr="disabled">Periksa perubahan</x-filament::button>
                @foreach (['assessment', 'review'] as $field)
                    @error($field)<p role="alert" class="text-danger-600">{{ $message }}</p>@enderror
                @endforeach
                @if ($assessmentDisplay !== [])
                    <p>Hasil asesmen: {{ $assessmentDisplay['comparison'] }}</p>
                    <p>Revisi {{ $assessmentDisplay['revision'] }} · {{ $assessmentDisplay['status'] }} · {{ $assessmentDisplay['active'] ? 'Run aktif' : 'Run historis' }}</p>
                    <p>Versi case saat asesmen: {{ $assessmentDisplay['case_version'] }} · Urutan asesmen: {{ $assessmentDisplay['sequence'] }}</p>
                    @foreach ($assessmentDisplay['changed_groups'] as $group)<p>Kelompok berubah: {{ $group }}</p>@endforeach
                    @foreach ($assessmentDisplay['issue_codes'] as $code)<p>Issue saat asesmen: {{ $code }}</p>@endforeach
                    @if ($assessmentDisplay['failure_code'] !== null)<p>Kode kendala asesmen: {{ $assessmentDisplay['failure_code'] }}</p>@endif
                    @if ($assessedRunId !== null)
                        <form wire:submit="reviewConversion" class="mt-3 space-y-3">
                            <label class="block">Alasan tinjauan <textarea wire:model="reviewReason" class="block w-full rounded border-gray-300 dark:bg-gray-900"></textarea></label>
                            <x-filament::button type="submit" wire:loading.attr="disabled">Catat tinjauan</x-filament::button>
                        </form>
                    @else
                        <p>Asesmen ini tidak menyediakan tindakan tinjauan.</p>
                    @endif
                @endif
            </x-filament::section>
        @endif
        <x-filament::section heading="Run tersimpan">
            <dl class="grid gap-3 sm:grid-cols-2">
                @foreach ($saved['summary'] as $label => $value)
                    <div><dt class="font-medium">{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
            <p>Total eksak: {{ $saved['total']['exact'] }} · Tampilan total: {{ $saved['total']['human'] }}</p>
            @foreach ($saved['review'] as $label => $value)<p>{{ $label }}: {{ $value }}</p>@endforeach
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
