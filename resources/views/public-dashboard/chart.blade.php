<article class="portal-card min-w-0">
    <h3 class="font-bold text-slate-900">{{ $title }}</h3>
    @if(!$values || collect($values)->whereNotNull('value')->isEmpty())
        <div class="h-52 grid place-content-center text-sm text-slate-500">Tidak ada data untuk pilihan ini.</div>
    @else
        @php($chartValues = array_map(fn ($v) => ['label'=>$showLabel($v['label']),'value'=>$v['value']], $values))
        <div class="overflow-y-auto mt-4" style="max-height:360px"><div style="position:relative;height:{{ $horizontal ? max(280,count($values)*32) : 280 }}px"><canvas id="{{ $chartId }}" data-public-chart data-chart-type="{{ $type }}" data-horizontal="{{ $horizontal ? 'true':'false' }}" data-values="{{ json_encode($chartValues,JSON_THROW_ON_ERROR) }}" aria-label="{{ $title }}" role="img"></canvas></div></div>
    @endif
</article>
