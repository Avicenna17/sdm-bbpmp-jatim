    <div class="relative bg-gradient-to-br from-slate-900 via-blue-950 to-indigo-950 text-white overflow-hidden py-10 sm:py-14 border-b border-blue-900/40">
        <!-- Background Pattern & Glow Effects -->
        <div class="absolute inset-0 opacity-10 pointer-events-none">
            <svg class="w-full h-full" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
                <defs>
                    <pattern id="hero-grid" width="40" height="40" patternUnits="userSpaceOnUse">
                        <path d="M 40 0 L 0 0 0 40" fill="none" stroke="#38bdf8" stroke-width="0.8"/>
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#hero-grid)" />
            </svg>
        </div>
        <div class="absolute -top-24 -right-24 w-96 h-96 bg-sky-500/15 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-blue-600/20 rounded-full blur-3xl pointer-events-none"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-8">

                <!-- Hero Left: Text & Badges -->
                <div class="max-w-2xl space-y-4">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-500/20 border border-blue-400/30 text-blue-200 text-xs font-medium backdrop-blur-xs">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                        <span>Executive Dashboard • Balai Besar Penjaminan Mutu Pendidikan</span>
                    </div>

                    <h2 class="text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight text-white leading-tight">
                        Transparansi & Analisis <span class="text-transparent bg-clip-text bg-gradient-to-r from-sky-400 via-blue-200 to-amber-300">Peta Kebutuhan Jabatan</span> Pegawai
                    </h2>

                    <p class="text-slate-300 text-sm sm:text-base leading-relaxed">
                        Sistem pemantauan agregat formasi pegawai, pemenuhan Analisis Beban Kerja (ABK), profil kepangkatan DUK, dan proyeksi suksesi pensiun berdasarkan periode yang telah dipublikasikan.
                    </p>

                    <!-- Trust & Compliance Badges -->
                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <div class="inline-flex items-center gap-1.5 text-xs text-slate-300 bg-white/5 border border-white/10 px-3 py-1.5 rounded-lg">
                            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span>Rekap agregat kepegawaian</span>
                        </div>
                        <div class="inline-flex items-center gap-1.5 text-xs text-slate-300 bg-white/5 border border-white/10 px-3 py-1.5 rounded-lg">
                            <svg class="w-4 h-4 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2 1 3 3 3h10c2 0 3-1 3-3V7c0-2-1-3-3-3H7c-2 0-3 1-3 3zM9 12h6M12 9v6"/></svg>
                            <span>Terintegrasi Formasi ABK & DUK</span>
                        </div>
                    </div>
                </div>

                <!-- Hero Right: Quick Jump Nav -->
                <div class="flex-shrink-0 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-1 gap-2.5 sm:gap-3 bg-white/5 p-4 rounded-2xl border border-white/10 backdrop-blur-sm">
                    <a href="#kpi-section" class="flex items-center gap-2.5 p-2.5 rounded-xl hover:bg-white/10 transition-colors text-xs font-semibold text-slate-200 hover:text-white">
                        <div class="w-7 h-7 rounded-lg bg-blue-600/30 flex items-center justify-center text-sky-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        </div>
                        <span>Ringkasan Metrik Data</span>
                    </a>
                    <a href="#grafik-section" class="flex items-center gap-2.5 p-2.5 rounded-xl hover:bg-white/10 transition-colors text-xs font-semibold text-slate-200 hover:text-white">
                        <div class="w-7 h-7 rounded-lg bg-indigo-600/30 flex items-center justify-center text-indigo-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/></svg>
                        </div>
                        <span>Visualisasi Grafik DUK</span>
                    </a>
                    <a href="#duk-rekap-section" class="flex items-center gap-2.5 p-2.5 rounded-xl hover:bg-white/10 transition-colors text-xs font-semibold text-slate-200 hover:text-white col-span-2 sm:col-span-1">
                        <div class="w-7 h-7 rounded-lg bg-amber-600/30 flex items-center justify-center text-amber-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        </div>
                        <span>Rekapitulasi & Proyeksi</span>
                    </a>
                </div>

            </div>
        </div>
    </div>
