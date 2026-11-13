<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    @PwaHead
    @include('partials.pwa-ios-splash')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Persyaratan Jabatan Fungsional - {{ $positionName }} | Portal Data Kepegawaian BBPMP</title>
    <meta name="description" content="Informasi daftar dokumen dan berkas persyaratan jabatan fungsional {{ $positionName }} di BBPMP Provinsi Jawa Timur.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-800 antialiased min-h-screen flex flex-col">
<a class="skip-link" href="#main">Lewati ke isi persyaratan</a>
@include('partials.header')
<main id="main" class="flex-grow py-8 sm:py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-2 text-xs text-slate-500 mb-6" aria-label="Breadcrumb">
            <a href="{{ route('dashboard') }}#duk-rekap-section" class="inline-flex items-center gap-1.5 font-semibold text-blue-900 hover:text-blue-700 hover:underline">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                <span>Kembali ke Peta Formasi</span>
            </a>
            <span class="text-slate-300">/</span>
            <span class="text-slate-500">Peta Jabatan</span>
            <span class="text-slate-300">/</span>
            <span class="text-slate-800 font-semibold truncate max-w-xs sm:max-w-md">Persyaratan Jabatan Fungsional</span>
        </nav>

        <section class="portal-card mb-8 border border-slate-200/90 shadow-sm relative overflow-hidden">
            <div class="absolute -top-16 -right-16 w-64 h-64 bg-blue-100/40 rounded-full blur-3xl pointer-events-none"></div>
            <div class="relative z-10">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-blue-100/80 text-blue-900 border border-blue-200">
                        <svg class="w-3.5 h-3.5 text-blue-700" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                        <span>Informasi Kepegawaian</span>
                    </span>
                    <span class="text-xs text-slate-400">&bull;</span>
                    <span class="text-xs text-slate-500 font-medium">BBPMP Provinsi Jawa Timur</span>
                </div>

                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
                    Persyaratan Jabatan Fungsional
                </h1>
                <p class="text-sm sm:text-base text-slate-600 mt-2 max-w-3xl leading-relaxed">
                    Daftar dokumen persyaratan administrasi dan berkas yang harus dipenuhi untuk menduduki formasi jabatan fungsional terpilih.
                </p>
                <!-- Selected Position -->
                <div class="mt-5 p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-blue-50/80 via-slate-50 to-indigo-50/50 border border-blue-200/80 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="flex items-start sm:items-center gap-3.5">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-900 via-blue-800 to-indigo-900 text-amber-400 p-2.5 shadow-md flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                            </svg>
                        </div>
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-blue-900 block mb-0.5">
                                Formasi Jabatan Terpilih:
                            </span>
                            <h2 class="text-lg sm:text-xl font-bold text-slate-900">
                                {{ $positionName }}
                            </h2>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 self-start md:self-center">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-700 shadow-2xs">
                            <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                            Jabatan Fungsional
                        </span>
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-blue-100/70 border border-blue-200 text-xs font-bold text-blue-900 shadow-2xs">
                            4 Kategori Jalur
                        </span>
                    </div>
                </div>
            </div>
        </section>
        <!-- Section Daftar Dokumen Persyaratan -->
        <div class="flex items-center justify-between gap-3 mb-6">
            <div>
                <p class="text-xs font-bold text-blue-900 uppercase tracking-wider">Katalog Persyaratan</p>
                <h3 class="text-xl sm:text-2xl font-bold text-slate-900 mt-1">Daftar Dokumen Persyaratan</h3>
            </div>
            <span class="hidden sm:inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">
                Pilih kategori sesuai jalur pengangkatan
            </span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach($categories as $category)
                @php
                    $hasFile = isset($category['has_file']) 
                        ? (bool) $category['has_file'] 
                        : (filled($category['url'] ?? null) && !in_array($category['url'], ['#', '{link_google_drive}', ''], true));
                @endphp
                <article class="portal-card !p-0 overflow-hidden border border-slate-200/90 shadow-sm hover:shadow-md hover:border-blue-300 transition-all duration-200 flex flex-col justify-between group">
 
                    <div class="p-6">
                        <div class="flex items-center justify-between gap-3 mb-4">
                            <span class="inline-flex items-center justify-center px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-blue-100 text-blue-900 border border-blue-200/80">
                                Kategori {{ $category['number'] }}
                            </span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 border border-slate-200/70">
                                {{ $category['badge'] }}
                            </span>
                        </div>

                        <!-- Category -->
                        <div class="flex items-start gap-3.5">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 border border-blue-200/80 text-blue-900 flex items-center justify-center flex-shrink-0 group-hover:bg-blue-900 group-hover:text-amber-400 transition-colors shadow-2xs">
                                @if($category['icon'] === 'first_appointment')
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                                    </svg>
                                @elseif($category['icon'] === 'transfer')
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                    </svg>
                                @elseif($category['icon'] === 'inpassing')
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                @else
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                                    </svg>
                                @endif
                            </div>

                            <div>
                                <h4 class="text-lg sm:text-xl font-bold text-slate-900 group-hover:text-blue-900 transition-colors">
                                    {{ $category['title'] }}
                                </h4>
                                <p class="text-xs sm:text-sm text-slate-600 mt-1.5 leading-relaxed">
                                    {{ $category['description'] }}
                                </p>
                            </div>
                        </div>
                        <!-- Keterangan Format/File -->
                        <div class="mt-5 p-3 rounded-xl bg-slate-50 border border-slate-200/80 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2 text-xs text-slate-600">
                                <svg class="w-4 h-4 text-blue-700 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                <span>Keterangan: <strong class="text-slate-800">{{ $category['type_label'] }}</strong></span>
                            </div>
                            @if($hasFile)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white border border-slate-200/80 text-[11px] font-semibold text-blue-900 shadow-2xs">
                                    <svg class="w-3 h-3 text-emerald-600" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/>
                                    </svg>
                                    Google Drive
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-slate-100 border border-slate-200 text-[11px] font-medium text-slate-500">
                                    <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                    Belum Ada File
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="p-4 sm:px-6 bg-slate-50/70 border-t border-slate-200/70">
                        @if($hasFile)
                            <a href="{{ $category['url'] }}" target="_blank" rel="noopener noreferrer" 
                               class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-blue-900 to-indigo-900 hover:from-blue-800 hover:to-indigo-800 text-white text-xs sm:text-sm font-semibold shadow-xs hover:shadow-md transition-all duration-200">
                                <span>Buka Berkas Persyaratan</span>
                                <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                </svg>
                            </a>
                        @else
                            <button type="button" disabled 
                                    class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-slate-100 border border-slate-200 text-slate-400 text-xs sm:text-sm font-semibold cursor-not-allowed select-none shadow-none">
                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span>Belum Tersedia</span>
                            </button>
                        @endif
                    </div>

                </article>
            @endforeach
        </div>

        <div class="mt-8 p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-blue-50/60 via-slate-50 to-indigo-50/40 border border-blue-200/80 shadow-xs">
            <div class="flex items-start gap-3">
                <div class="w-6 h-6 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5">
                    i
                </div>
                <div class="text-xs sm:text-sm text-slate-700 leading-relaxed">
                    <strong class="font-semibold text-slate-900">Catatan :</strong>
                    Apabila link Google Drive membutuhkan akses otorisasi atau terdapat pertanyaan formasi, silakan menghubungi Bagian Kepegawaian BBPMP Provinsi Jawa Timur.
                </div>
            </div>
        </div>

    </div>
</main>
@include('partials.footer')
@RegisterServiceWorkerScript
</body>
</html>

