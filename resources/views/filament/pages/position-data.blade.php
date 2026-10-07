<x-filament-panels::page>
    @php($recap = $this->positionSummary())
    <style>
        .position-widgets{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}.position-widget{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;min-width:0}.position-widget:first-child{grid-column:1/-1}.position-widget h2{font-weight:600;font-size:16px;margin-bottom:8px}.position-muted{font-size:13px;color:#64748b}.position-chart-list{display:grid;gap:20px;max-height:380px;overflow:auto;margin-top:16px;padding-right:10px}.position-chart-label{font-size:14px;font-weight:500;overflow-wrap:anywhere;margin-bottom:8px}.position-bar-row{display:grid;grid-template-columns:90px minmax(40px,1fr) 48px;align-items:center;gap:10px;margin-top:5px;font-size:12px}.position-bar-track{height:10px;background:#f1f5f9;border-radius:5px}.position-bar{height:10px;border-radius:5px}.position-year{display:flex;justify-content:space-between;gap:12px;margin-bottom:8px;font-size:14px}.position-period{border:1px solid #cbd5e1;border-radius:8px;padding:8px 36px 8px 12px;background-repeat:no-repeat;background-position:right 10px center;background-size:16px}.position-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}.dark .position-widget{background:#18181b;border-color:#3f3f46}.dark .position-muted{color:#a1a1aa}.dark .position-bar-track{background:#3f3f46}.dark .position-period{background-color:#18181b}@media(max-width:767px){.position-widgets{grid-template-columns:1fr}}
        .position-job-columns,.position-year-columns{display:flex;align-items:flex-start;max-height:none;padding:12px 0 16px;gap:24px}.position-job-columns>li{flex:0 0 250px}.position-columns{display:flex;gap:12px}.position-column{flex:1;text-align:center;font-size:12px}.position-column-plot{height:320px;position:relative;background:linear-gradient(to bottom,transparent 159px,#94a3b8 159px,#94a3b8 160px,transparent 160px)}.position-upright{position:absolute;left:20%;width:60%;border-radius:4px}.position-column-plot strong{position:absolute;top:0;left:0;width:100%}.position-year-columns>li{flex:0 0 100px}.position-year-plot{height:190px;display:flex;align-items:flex-end;justify-content:center;border-bottom:1px solid #94a3b8}.position-year-plot>div{width:40px;border-radius:4px 4px 0 0}
    </style>
    <div class="position-toolbar">
        <p class="text-sm font-medium">Grafik mengikuti filter dan pencarian tabel · {{ $recap['records']->count() }} data jabatan.</p>
    </div>
    <div class="position-widgets" wire:loading.class="opacity-50">
        <section class="position-widget" aria-label="Perbandingan jabatan dan kebutuhan">
            <h2>Jabatan dan kebutuhan</h2>
            <p class="position-muted">Jumlah pemangku, kebutuhan, dan kosong sesuai file sumber. Nilai kosong negatif menunjukkan kelebihan pemangku.</p>
            <ul class="position-chart-list position-job-columns">
                @forelse($recap['records'] as $record)
                    <li wire:key="position-chart-{{ $record->id }}">
                        <div class="position-chart-label">{{ $record->position_name }} · {{ $record->position_type ?: '-' }}</div>
                        <p class="position-muted">{{ $record->work_unit ?: '-' }} · Kelas {{ $record->position_class ?? '-' }}</p>
                        <div class="position-columns">
                        @foreach(['incumbent_count'=>['Pemangku','#059669'],'requirement_count'=>['Kebutuhan','#6366f1'],'vacancy_count'=>['Kosong','#ef4444']] as $field=>[$label,$color])
                            <div class="position-column"><div class="position-column-plot"><div class="position-upright" style="height:{{ abs($record->$field ?? 0)/$recap['maximum']*140 }}px;background:{{ $color }};{{ ($record->$field ?? 0) < 0 ? 'top:160px' : 'bottom:160px' }}"></div><strong>{{ $record->$field ?? '-' }}</strong></div><span>{{ $label }}</span></div>
                        @endforeach
                        </div>
                    </li>
                @empty<li class="position-muted">Belum ada data yang sesuai dengan filter dan pencarian.</li>@endforelse
            </ul>
        </section>
        @foreach(['RETIREMENT'=>['Pensiun per tahun','#0ea5e9'],'REQUIREMENT'=>['Proyeksi kebutuhan per tahun','#6366f1']] as $metric=>[$title,$color])
            @php($series = $recap['series'][$metric])
            @php($maximum = max(1, collect($series)->max('value') ?? 0))
            <section class="position-widget" aria-label="{{ $title }}">
                <h2>{{ $title }}</h2>
                <p class="position-muted">@if($series)Rentang {{ array_key_first($series) }}–{{ array_key_last($series) }} · Total dari data jabatan terfilter.@else Tahun belum tersedia untuk data terfilter.@endif</p>
                <ul class="position-chart-list position-year-columns">
                    @forelse($series as $year=>$item)
                        <li wire:key="projection-chart-{{ $metric }}-{{ $year }}">
                            <div class="position-year"><span>{{ $year }}</span><strong>{{ $item['value'] ?? '-' }}</strong></div>
                            <div class="position-year-plot" aria-hidden="true"><div style="height:{{ ($item['value'] ?? 0)/$maximum*100 }}%;background:{{ $color }}"></div></div>
                            @if($item['known'] < $item['total'])<p class="position-muted" style="margin-top:5px">Data tersedia: {{ $item['known'] }} dari {{ $item['total'] }} jabatan{{ $item['known'] ? '; total sementara.' : '.' }}</p>@endif
                        </li>
                    @empty<li class="position-muted">Belum ada data tahunan yang dapat ditampilkan.</li>@endforelse
                </ul>
            </section>
        @endforeach
    </div>
    {{ $this->table }}
</x-filament-panels::page>
