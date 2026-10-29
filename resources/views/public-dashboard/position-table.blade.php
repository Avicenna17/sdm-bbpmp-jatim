<div id="{{ $id }}" class="portal-card !p-0 overflow-hidden border border-slate-200/90 shadow-sm hover:shadow-md transition-shadow duration-300 scroll-mt-24">
    <!-- Header Card -->
    <div class="p-5 sm:p-6 bg-gradient-to-r from-slate-50 via-white to-blue-50/40 border-b border-slate-200/80">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <!-- Title & Context -->
            <div class="flex items-start gap-3.5">
                <div class="flex-shrink-0 w-11 h-11 rounded-xl {{ $icon === 'bbpmp' ? 'bg-gradient-to-br from-blue-900 via-blue-800 to-indigo-900 text-amber-400' : 'bg-gradient-to-br from-indigo-900 via-indigo-800 to-slate-900 text-sky-400' }} p-2.5 shadow-md flex items-center justify-center">
                    @if($icon === 'bbpmp')
                        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2L1 7l11 5 9-4.09V17h2V7L12 2z"/>
                            <path d="M4.5 10.5v5.88c0 3.12 3.36 5.62 7.5 5.62s7.5-2.5 7.5-5.62v-5.88l-7.5 3.41-7.5-3.41z" opacity="0.85"/>
                        </svg>
                    @else
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                    @endif
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2 mb-1">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $badgeColor === 'blue' ? 'bg-blue-100/80 text-blue-900 border border-blue-200' : 'bg-indigo-100/80 text-indigo-900 border border-indigo-200' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $badgeColor === 'blue' ? 'bg-blue-600' : 'bg-indigo-600' }}"></span>
                            {{ $unitBadge }}
                        </span>
                        <span class="text-xs text-slate-400">&bull;</span>
                        <span class="text-xs text-slate-500 font-medium">Rekapitulasi Formasi ABK & DUK</span>
                    </div>
                    <h3 class="text-lg sm:text-xl font-extrabold text-slate-900 tracking-tight">
                        {{ $title }}
                    </h3>
                    <p class="text-xs sm:text-sm text-slate-500 mt-1">
                        Daftar lengkap formasi jabatan, beban bezetting saat ini, proyeksi kebutuhan, dan selisih formasi.
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 self-start lg:self-center">
                <div class="px-3 py-1.5 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-700 shadow-2xs flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                    <span><strong>{{ count($peta['top']) }}</strong> Formasi</span>
                </div>
                <div class="px-3 py-1.5 rounded-xl bg-blue-50 border border-blue-200 text-xs font-bold text-blue-900 shadow-2xs flex items-center gap-1.5">
                    <span><strong>{{ number_format($peta['incumbents'], 0, ',', '.') }}</strong> Bezetting</span>
                </div>
                <div class="px-3 py-1.5 rounded-xl bg-amber-50 border border-amber-200 text-xs font-bold text-amber-900 shadow-2xs flex items-center gap-1.5">
                    <span><strong>{{ number_format($peta['requirements'], 0, ',', '.') }}</strong> Kebutuhan</span>
                </div>
            </div>
        </div>
    </div>

    <div class="overflow-x-auto overflow-y-auto max-h-[640px] focus:outline-none" tabindex="0" role="region" aria-label="{{ $title }}">
        <table class="w-full text-sm text-left border-collapse">
            <thead class="sticky top-0 z-20 bg-slate-100/95 backdrop-blur-md text-xs font-bold uppercase tracking-wider text-slate-700 border-b border-slate-200 shadow-2xs">
                <tr>
                    <th scope="col" class="px-4 py-3.5 text-center w-14">No</th>
                    <th scope="col" class="px-4 py-3.5 min-w-72">Nama / Formasi Jabatan</th>
                    <th scope="col" class="px-4 py-3.5 text-center w-36">Jenis</th>
                    <th scope="col" class="px-4 py-3.5 text-center w-24">Kelas</th>
                    <th scope="col" class="px-4 py-3.5 text-center w-28 text-blue-950" title="Jumlah pegawai Bezettingformasi saat ini">Bezetting</th>
                    <th scope="col" class="px-4 py-3.5 text-center w-28 text-slate-800" title="Jumlah kebutuhan formasi berdasarkan Analisis Beban Kerja (ABK)">Kebutuhan</th>
                    <th scope="col" class="px-4 py-3.5 text-center w-28 text-amber-900" title="Formasi yang masih kosong/belum terisi Bezetting">Kosong</th>
                    <th scope="col" class="px-4 py-3.5 text-center w-44" title="Selisih = Bezetting dikurangi Kebutuhan. Minus (-) berarti kekurangan, Plus (+) berarti kelebihan">Selisih (Bezetting − Kebutuhan)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @forelse($peta['top'] as $row)
                    @php
                        $selisih = ($row['incumbents'] !== null && $row['requirements'] !== null) ? ($row['incumbents'] - $row['requirements']) : null;
                    @endphp
                    <tr class="hover:bg-blue-50/40 transition-colors duration-150 even:bg-slate-50/30 group">
                        <!-- Nomor -->
                        <td class="px-4 py-3 text-center text-xs font-medium text-slate-400 group-hover:text-blue-900">
                            {{ $loop->iteration }}
                        </td>

                        <!-- Nama Jabatan -->
                        <th scope="row" class="px-4 py-3 font-semibold text-slate-900 group-hover:text-blue-900">
                            <div class="flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-slate-300 group-hover:bg-blue-600 transition-colors"></span>
                                <span>{{ $row['position_name'] }}</span>
                            </div>
                        </th>

                        <!-- Jenis Jabatan -->
                        <td class="px-4 py-3 text-center">
                            @if(filled($row['position_type']))
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-medium bg-slate-100 text-slate-700 border border-slate-200/80 group-hover:bg-white transition-colors">
                                    {{ $row['position_type'] }}
                                </span>
                            @else
                                <span class="text-slate-400 text-xs">-</span>
                            @endif
                        </td>

                        <!-- Kelas Jabatan -->
                        <td class="px-4 py-3 text-center">
                            @if(filled($row['position_class']))
                                <span class="inline-flex items-center justify-center min-w-8 px-2 py-0.5 rounded-md text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200/60">
                                    {{ $row['position_class'] }}
                                </span>
                            @else
                                <span class="text-slate-400 text-xs">-</span>
                            @endif
                        </td>

                        <!-- Bezetting -->
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center justify-center min-w-8 px-2.5 py-1 rounded-lg bg-blue-50/80 text-blue-950 font-bold text-xs border border-blue-100 group-hover:bg-blue-100 transition-colors">
                                {{ $row['incumbents'] ?? '-' }}
                            </span>
                        </td>

                        <!-- Kebutuhan -->
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center justify-center min-w-8 px-2.5 py-1 rounded-lg bg-slate-100 text-slate-800 font-semibold text-xs border border-slate-200/60">
                                {{ $row['requirements'] ?? '-' }}
                            </span>
                        </td>

                        <!-- Kosong -->
                        <td class="px-4 py-3 text-center">
                            @if(($row['vacancies'] ?? 0) > 0)
                                <span class="inline-flex items-center justify-center min-w-8 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200/80 shadow-2xs">
                                    {{ $row['vacancies'] }}
                                </span>
                            @else
                                <span class="text-xs text-slate-400 font-medium">0</span>
                            @endif
                        </td>

                        <!-- Selisih -->
                        <td class="px-4 py-3 text-center">
                            @if($selisih === null)
                                <span class="text-xs text-slate-400">-</span>
                            @elseif($selisih < 0)
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200/80 shadow-2xs" title="Kekurangan formasi: {{ abs($selisih) }}">
                                    <span>{{ $selisih }}</span>
                                    <span class="sr-only">(Kurang)</span>
                                </span>
                            @elseif($selisih > 0)
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/80 shadow-2xs" title="Kelebihan formasi: +{{ $selisih }}">
                                    <span>+{{ $selisih }}</span>
                                    <span class="sr-only">(Lebih)</span>
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-200/60" title="Formasi terpenuhi seimbang">
                                    0 (Penuh)
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center text-slate-500">
                                <svg class="w-12 h-12 text-slate-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <p class="text-sm font-semibold text-slate-700">Tidak ada data untuk pilihan ini</p>
                                <p class="text-xs text-slate-400 mt-1">Coba sesuaikan kata kunci pencarian atau reset filter jabatan di atas.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot class="sticky bottom-0 z-20 bg-slate-100/95 backdrop-blur-md border-t-2 border-slate-300 shadow-sm font-bold text-slate-900">
                <tr>
                    <th colspan="4" scope="row" class="px-4 py-3.5 text-left">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-blue-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            <span>Total Sesuai Filter</span>
                        </div>
                    </th>
                    <td class="px-4 py-3.5 text-center">
                        <span class="inline-flex items-center justify-center px-3 py-1 rounded-lg bg-blue-100 text-blue-950 font-extrabold text-xs">
                            {{ number_format($peta['incumbents'], 0, ',', '.') }}
                        </span>
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        <span class="inline-flex items-center justify-center px-3 py-1 rounded-lg bg-slate-200 text-slate-900 font-extrabold text-xs">
                            {{ number_format($peta['requirements'], 0, ',', '.') }}
                        </span>
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        <span class="inline-flex items-center justify-center px-3 py-1 rounded-full text-xs font-bold {{ $peta['vacancies'] > 0 ? 'bg-amber-100 text-amber-900' : 'bg-slate-200 text-slate-600' }}">
                            {{ number_format($peta['vacancies'], 0, ',', '.') }}
                        </span>
                    </td>
                    <td class="px-4 py-3.5 text-center">
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-extrabold {{ $gap < 0 ? 'bg-rose-100 text-rose-800 border border-rose-300' : ($gap > 0 ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-slate-200 text-slate-800') }}">
                            {{ $gap > 0 ? '+'.$gap : $gap }}
                        </span>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
