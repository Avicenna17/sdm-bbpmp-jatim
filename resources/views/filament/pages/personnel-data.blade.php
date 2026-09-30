<x-filament-panels::page>
    @php
        $summary = $this->personnelSummary();
        $activeGroup = $this->tableFilters['employment_group']['value'] ?? '';
        $colors = ['#059669', '#6366f1', '#f59e0b', '#0ea5e9', '#e879a0', '#64748b'];
        $segmentColor = fn ($field, $label, $index) => match (true) {
            $field === 'gender' => ['Laki-laki' => '#2563eb', 'Perempuan' => '#ec4899'][$label] ?? '#64748b',
            $label === 'PNS' => '#86efac',
            default => $colors[$index % count($colors)],
        };
    @endphp
    <style>
        .personnel-recap{display:grid;gap:20px}.personnel-recap .recap-toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}.recap-groups{display:flex;flex-wrap:wrap;gap:8px}.recap-groups button{padding:9px 14px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px}.recap-groups button[aria-pressed=true]{background:#047857;color:white;border-color:#047857}.recap-period{min-width:220px;border:1px solid #cbd5e1;border-radius:9px;padding:9px 36px 9px 12px;background-repeat:no-repeat;background-position:right 10px center;background-size:16px}.recap-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.recap-stat,.recap-chart{padding:20px;border:1px solid #e2e8f0;border-radius:12px;background:white}.recap-stat > .recap-muted{font-weight:700}.recap-stat strong{display:block;font-size:30px}.recap-muted{color:#64748b;font-size:13px}.recap-charts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.recap-chart h3{font-weight:600;margin-bottom:16px}.recap-chart:last-child{grid-column:1/-1}.recap-bars{display:grid;gap:14px;max-height:300px;overflow-y:auto;padding-right:8px}.recap-label{display:flex;gap:12px;justify-content:space-between;font-size:13px;margin-bottom:5px}.recap-label span{overflow-wrap:anywhere}.recap-label strong{white-space:nowrap}.recap-track{height:9px;background:#f1f5f9;border-radius:5px}.recap-fill{height:9px;background:#059669;border-radius:5px}.recap-donut-layout{display:flex;align-items:center;flex-wrap:wrap;gap:24px}.recap-donut{width:145px;height:145px;flex-shrink:0;border-radius:50%;display:grid;place-items:center}.recap-donut-center{width:104px;height:104px;border-radius:50%;background:white;display:grid;place-content:center;text-align:center}.recap-donut-center strong{font-size:26px}.recap-legend{flex:1;min-width:150px;display:grid;gap:10px}.recap-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px}.dark .recap-stat,.dark .recap-chart,.dark .recap-donut-center{background:#18181b;border-color:#3f3f46}.dark .recap-muted{color:#a1a1aa}.dark .recap-period{background-color:#18181b}.dark .recap-track{background:#3f3f46}@media(max-width:767px){.recap-charts,.recap-stats{grid-template-columns:1fr}.recap-chart:last-child{grid-column:auto}}
        .recap-chart{min-width:0}.recap-columns{display:flex;gap:24px;overflow-x:auto;padding:28px 10px 12px}.recap-columns li{flex:0 0 150px;text-align:center;font-size:12px}.recap-column-plot{height:180px;display:flex;align-items:flex-end;justify-content:center;border-bottom:1px solid #94a3b8;margin-bottom:12px}.recap-column{width:48px;background:#059669;border-radius:5px 5px 0 0;position:relative}.recap-column strong{position:absolute;bottom:100%;left:0;width:100%;padding-bottom:4px}.recap-columns li>span{display:block;overflow-wrap:anywhere}.recap-columns small{display:block;color:#64748b;margin-top:5px}
    </style>
    <section class="personnel-recap" aria-label="Rekap Daftar Pegawai" wire:loading.class="opacity-50">
        <div class="recap-toolbar">
            <div class="recap-groups" role="group" aria-label="Kelompok pegawai">
                @foreach([''=>'Semua','ASN'=>'ASN','PPNPN'=>'PPNPN'] as $value=>$label)
                    <button type="button" wire:click="selectPersonnelGroup('{{ $value }}')" aria-pressed="{{ $activeGroup === $value ? 'true' : 'false' }}" wire:loading.attr="disabled">{{ $label }}</button>
                @endforeach
            </div>
            <div><label for="recap-period" class="sr-only">Bulan dan tahun</label><select id="recap-period" class="recap-period" wire:model.live="tableFilters.reporting_period_id.value"><option value="">Semua periode</option>@foreach(\App\Models\ReportingPeriod::orderByDesc('period_month')->get() as $period)<option value="{{ $period->id }}">{{ $period->label }}</option>@endforeach</select></div>
        </div>
        @if($summary['multiplePeriods'])<p class="recap-muted">Semua periode dipilih: seorang pegawai dapat terhitung pada beberapa periode. Pilih satu periode untuk melihat jumlah pegawai periode tersebut.</p>@endif
        <div class="recap-stats">
            <div class="recap-stat"><span class="recap-muted">{{ $summary['multiplePeriods'] ? 'Jumlah catatan pegawai' : 'Jumlah pegawai' }}</span><strong>{{ number_format($summary['total'],0,',','.') }}</strong></div>
            <div class="recap-stat"><span class="recap-muted">Jenis jabatan terisi</span><strong>{{ $summary['positions'] }}</strong></div>
            <div class="recap-stat"><span class="recap-muted">Penempatan baru terisi</span><strong>{{ $summary['placements'] }}</strong></div>
        </div>
        @if($summary['total'] === 0)
            <x-filament::section><p>Belum ada data yang sesuai dengan filter dan pencarian. Ubah pilihan atau hapus filter pada tabel.</p></x-filament::section>
        @else
            <details open><summary class="text-sm font-medium" style="cursor:pointer;margin-bottom:16px">Grafik rekap · jumlah dan persentase dari {{ $summary['total'] }} data</summary>
            <div class="recap-charts">
                @foreach($summary['charts'] as $field=>$chart)
                <article class="recap-chart" wire:key="recap-{{ $field }}"><h3>{{ $chart['title'] }}</h3>
                    @if(in_array($field,['gender','employment_status']))
                        @php
                            $stops=[]; $offset=0;
                            $i=0; foreach($chart['values'] as $label=>$count){$end=$offset+($count/$summary['total']*100);$stops[]=$segmentColor($field,$label,$i++).' '.$offset.'% '.$end.'%';$offset=$end;}
                        @endphp
                        <div class="recap-donut-layout">
                            <div class="recap-donut" aria-hidden="true" style="background:conic-gradient({{ implode(',',$stops) }})"><div class="recap-donut-center"><strong>{{ $summary['total'] }}</strong><span class="recap-muted">data</span></div></div>
                            <ul class="recap-legend">@foreach($chart['values'] as $label=>$count)<li class="recap-label"><span><i class="recap-dot" style="background:{{ $segmentColor($field,$label,$loop->index) }}" aria-hidden="true"></i>{{ $label }}</span><strong>{{ $count }} · {{ number_format($count/$summary['total']*100,1,',','.') }}%</strong></li>@endforeach</ul>
                        </div>
                    @elseif($field === 'placement_current')
                        <ul class="recap-columns">@foreach($chart['values'] as $label=>$count)<li><div class="recap-column-plot"><div class="recap-column" style="height:{{ $count/max($chart['values'])*100 }}%"><strong>{{ $count }}</strong></div></div><span>{{ $label }}</span><small>{{ number_format($count/$summary['total']*100,1,',','.') }}%</small></li>@endforeach</ul>
                    @else
                        <ul class="recap-bars">@foreach($chart['values'] as $label=>$count)<li><div class="recap-label"><span>{{ $label }}</span><strong>{{ $count }} · {{ number_format($count/$summary['total']*100,1,',','.') }}%</strong></div><div class="recap-track" aria-hidden="true"><div class="recap-fill" style="width:{{ $count/$summary['total']*100 }}%"></div></div></li>@endforeach</ul>
                    @endif
                </article>
                @endforeach
            </div><p class="recap-muted" style="margin-top:12px">Tanda “-” berarti data belum tersedia.</p>
            </details>
        @endif
    </section>
    {{ $this->table }}
</x-filament-panels::page>
