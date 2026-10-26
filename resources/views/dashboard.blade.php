<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portal Data Kepegawaian BBPMP Jawa Timur</title>
    <meta name="description" content="Rekap publik pegawai, peta jabatan, dan proyeksi kebutuhan BBPMP Provinsi Jawa Timur.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-800 antialiased">
<a class="skip-link" href="#main">Lewati ke isi dashboard</a>
@include('public-dashboard.header')
@include('public-dashboard.hero')
<main id="main">
@if(!$data)
    <section class="max-w-7xl mx-auto px-4 py-16"><div class="portal-card text-center"><h2 class="text-2xl font-bold text-slate-900">Dashboard siap menerima data</h2><p class="mt-3 text-slate-500">Belum ada periode yang dipublikasikan. Data tampil setelah diperiksa dan dipublikasikan oleh administrator.</p><a class="portal-button mt-6" href="{{ route('filament.admin.auth.login') }}">Login Admin</a></div></section>
@else
    @php
        $peta = $data['positions'];
        $ratio = $peta['requirements'] > 0 ? round($peta['incumbents']/$peta['requirements']*100,1) : null;
        $gap = $peta['incumbents']-$peta['requirements'];
        $showLabel = fn ($value) => match ($value) { 'UNKNOWN','Belum diisi' => '-', 'PPPK_PARUH_WAKTU'=>'PPPK Paruh Waktu', 'L'=>'Laki-laki', 'P'=>'Perempuan', default => $value };
    @endphp
    <section id="kpi-section" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 -mt-6 relative z-20">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            @foreach([
                ['Total '.$filters['employment_group'],number_format($data['total'],0,',','.'),'Pegawai sesuai filter'],
                ['Kebutuhan (ABK)',number_format($peta['requirements'],0,',','.'),'Formasi sesuai filter jabatan'],
                ['Rasio keterisian',$ratio === null ? '-' : number_format($ratio,1,',','.').'%', 'Pemangku / kebutuhan formasi'],
                ['Selisih formasi',($gap>0?'+':'').number_format($gap,0,',','.'),'Pemangku dikurangi kebutuhan'],
                ['Pensiun 5 tahun',number_format($peta['retirement_total'],0,',','.'),'Total sesuai kolom sumber'],
            ] as [$label,$value,$caption])
            <article class="portal-card"><span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $label }}</span><div class="text-3xl font-extrabold {{ $label === 'Selisih formasi' && $gap < 0 ? 'text-red-600' : 'text-blue-950' }} mt-4">{{ $value }}</div><p class="text-xs text-slate-500 mt-3">{{ $caption }}</p></article>
            @endforeach
        </div>
        <p class="text-xs text-slate-500 mt-4">{{ ($period?->label ?? 'DUK belum dipublikasikan') }} · Diperbarui {{ $period?->published_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB.</p>
    </section>
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-8" aria-labelledby="filter-heading">
        <h2 id="filter-heading" class="text-xl font-bold text-slate-900 mb-4">Filter data kepegawaian</h2>
        <form method="get" action="{{ route('dashboard') }}" class="portal-card" data-dashboard-filter>
            <input type="hidden" name="period" value="{{ $period?->period_month?->format('Y-m') }}">
            @foreach(['position_search','position_type'] as $field)@if(filled($filters[$field]??null))<input type="hidden" name="{{ $field }}" value="{{ $filters[$field] }}">@endif@endforeach
            <div class="flex flex-wrap items-end gap-5">
                <fieldset><legend class="text-xs font-semibold text-slate-600 mb-2">Kelompok pegawai</legend><div class="flex gap-2">@foreach(['ASN','PPNPN'] as $group)<label class="portal-segment"><input type="radio" data-auto-filter name="employment_group" value="{{ $group }}" @checked($filters['employment_group']===$group)><span>{{ $group }}</span></label>@endforeach</div></fieldset>
            </div>
            <details class="mt-5" @if(count(array_filter(\Illuminate\Support\Arr::only($filters,array_keys(\App\Domain\Dashboard\DashboardQuery::FILTERS))))>0) open @endif><summary class="text-sm font-semibold text-blue-900 cursor-pointer">Filter lebih lanjut</summary><div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-4">
                @foreach(\App\Domain\Dashboard\DashboardQuery::FILTERS as $field=>$label)<label class="portal-label">{{ $label }}<select name="{{ $field }}" class="portal-input"><option value="">Semua</option>@foreach($data['options'][$field] as $value)<option value="{{ $value }}" @selected(($filters[$field]??'')===(string)$value)>{{ $showLabel($value) }}</option>@endforeach</select></label>@endforeach
                <div class="flex flex-wrap items-end gap-2"><button class="portal-button" type="submit">Terapkan filter</button><a class="portal-secondary" data-filter-reset href="{{ route('dashboard', \Illuminate\Support\Arr::except($filters, array_keys(\App\Domain\Dashboard\DashboardQuery::FILTERS)) + ['period'=>$period?->period_month?->format('Y-m')]) }}">Reset filter</a></div>
            </div></details>
        </form>
    </section>
    <section id="grafik-section" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-12">
        <div class="flex flex-wrap justify-between gap-3 items-end mb-6"><div><p class="text-xs font-bold text-blue-900 uppercase tracking-wider">01 / Visualisasi statistik pegawai</p><h2 class="text-2xl font-bold text-slate-900 mt-2">Komposisi {{ $filters['employment_group'] }}</h2></div></div>
        @foreach([['employment_status', 'gender'], ['education_level', 'placement_current'], ['grade_code', 'position_name', 'position_class']] as $fields)
        <div class="grid grid-cols-1 {{ count($fields) === 2 ? 'lg:grid-cols-2' : 'lg:grid-cols-3' }} gap-6 mb-6">
            @foreach($fields as $field)
                @php($chart = $data['charts'][$field])
                @include('public-dashboard.chart',['title'=>$chart['title'],'values'=>$chart['values'],'type'=>in_array($field,['gender','employment_status'])?'doughnut':'bar','horizontal'=>in_array($field,['position_name','placement_current']),'chartId'=>'personnel-'.$field])
            @endforeach
        </div>
        @endforeach
    </section>
    <section id="duk-rekap-section" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-12 pb-12">
        <div class="mb-6"><p class="text-xs font-bold text-blue-900 uppercase tracking-wider">02 / Tabulasi peta jabatan</p><h2 class="text-2xl font-bold text-slate-900 mt-2">Rekapitulasi struktur dan proyeksi kebutuhan</h2><p class="text-sm text-slate-500 mt-2">Peta Jabatan terkini, berlaku lintas periode DUK. {{ $positionState->published_at ? 'Dipublikasikan '.$positionState->published_at->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB' : 'Belum dipublikasikan' }}.</p></div>
        <form method="get" action="{{ route('dashboard') }}#duk-rekap-section" class="portal-card flex flex-wrap gap-4 items-end mb-6" data-dashboard-filter>
            @foreach($filters as $key=>$value)@if(!in_array($key,['position_search','position_type']) && filled($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif@endforeach
            @if(empty($filters['period']))<input type="hidden" name="period" value="{{ $period?->period_month?->format('Y-m') }}">@endif
            <label class="portal-label grow">Cari jabatan<input class="portal-input" type="search" data-auto-search name="position_search" value="{{ $filters['position_search']??'' }}" placeholder="Nama jabatan" maxlength="255"></label>
            <label class="portal-label">Jenis jabatan<select class="portal-input" name="position_type" data-auto-filter><option value="">Semua</option>@foreach($peta['types'] as $type)<option value="{{ $type }}" @selected(($filters['position_type']??'')===$type)>{{ $type }}</option>@endforeach</select></label>
            <noscript><button class="portal-button" type="submit">Terapkan filter jabatan</button></noscript><a class="portal-secondary" data-filter-reset href="{{ route('dashboard',\Illuminate\Support\Arr::except($filters,['position_search','position_type'])+['period'=>$period?->period_month?->format('Y-m')]) }}#duk-rekap-section">Reset jabatan</a>
        </form>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            @foreach(['REQUIREMENT'=>'Proyeksi kebutuhan per tahun','RETIREMENT'=>'Pensiun per tahun'] as $metric=>$label)
                @include('public-dashboard.chart',['title'=>$label,'values'=>$peta['projections'][$metric]??[],'type'=>'bar','horizontal'=>false,'chartId'=>'projection-'.$metric])
            @endforeach
        </div>
        <!-- Navigasi Cepat Kedua Tabel -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6 p-2 rounded-2xl bg-white border border-slate-200/80 shadow-2xs">
            <div class="flex items-center gap-2 pl-3">
                <svg class="w-4 h-4 text-blue-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
                <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Filter Tabel Formasi:</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="#tabel-kepala-bbpmp" class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-bold bg-blue-50 text-blue-900 border border-blue-200/80 hover:bg-blue-100 transition-all shadow-2xs">
                    <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                    <span>Dibawah Kepala BBPMP JATIM</span>
                </a>
                <a href="#tabel-kabag-umum" class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-bold bg-indigo-50 text-indigo-900 border border-indigo-200/80 hover:bg-indigo-100 transition-all shadow-2xs">
                    <span class="w-2 h-2 rounded-full bg-indigo-600"></span>
                    <span>Dibawah Kepala Bagian Umum</span>
                </a>
            </div>
        </div>

        <!-- 2 Tabel Peta Jabatan -->
        <div class="space-y-8">
            @include('public-dashboard.position-table', [
                'id' => 'tabel-kepala-bbpmp',
                'title' => 'Kebutuhan dan ketersediaan pegawai dibawah Kepala BBPMP JATIM',
                'unitBadge' => 'Kepala BBPMP JATIM',
                'badgeColor' => 'blue',
                'icon' => 'bbpmp',
                'peta' => $peta,
                'gap' => $gap,
            ])

            @include('public-dashboard.position-table', [
                'id' => 'tabel-kabag-umum',
                'title' => 'Kebutuhan dan ketersediaan pegawai dibawah Kepala Bagian Umum',
                'unitBadge' => 'Kepala Bagian Umum',
                'badgeColor' => 'indigo',
                'icon' => 'kabag',
                'peta' => $peta,
                'gap' => $gap,
            ])
        </div>
    </section>
@endif
</main>
<footer class="border-t border-slate-200 bg-white"><div class="max-w-7xl mx-auto px-6 py-8 text-center text-xs text-slate-500"><strong class="text-slate-800">BBPMP Provinsi Jawa Timur</strong> &copy; {{ date('Y') }} &middot; Portal Data Kepegawaian</div></footer>
</body></html>
