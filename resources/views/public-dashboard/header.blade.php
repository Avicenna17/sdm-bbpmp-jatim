    <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-slate-200/80 transition-all">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">

                <!-- Logo & Brand Title -->
                <div class="flex items-center gap-3.5">
                    <div class="flex-shrink-0 w-11 h-11 rounded-xl bg-gradient-to-br from-blue-900 via-blue-800 to-sky-600 p-0.5 shadow-md shadow-blue-900/10 flex items-center justify-center">
                        <div class="w-full h-full bg-blue-950 rounded-[10px] flex items-center justify-center">
                            <!-- Kemendikdasmen / BBPMP Emblem Icon -->
                            <svg class="w-6 h-6 text-amber-400" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2L1 7l11 5 9-4.09V17h2V7L12 2z"/>
                                <path d="M4.5 10.5v5.88c0 3.12 3.36 5.62 7.5 5.62s7.5-2.5 7.5-5.62v-5.88l-7.5 3.41-7.5-3.41z" opacity="0.85"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-extrabold tracking-wider text-blue-900 uppercase">KEMENDIKDASMEN</span>
                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-slate-300"></span>
                            <span class="text-xs font-semibold text-slate-500">Jawa Timur</span>
                        </div>
                        <h1 class="text-base sm:text-lg font-bold text-slate-900 leading-tight">
                            PORTAL DATA KEPEGAWAIAN BBPMP
                        </h1>
                        <p class="text-xs text-slate-500 font-medium hidden sm:block">
                            Sistem Informasi DUK & Peta Kebutuhan Jabatan (ABK)
                        </p>
                    </div>
                </div>

                <!-- Right Nav Items: Live Status & Admin Login Button -->
                <div class="flex items-center gap-3 sm:gap-4">
                    <!-- Live Data Badge -->
                    <div class="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold shadow-2xs">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        <span data-public-period>Periode • {{ $period?->label ?? 'Belum dipublikasikan' }}</span>
                    </div>

                    <!-- Login Admin CTA -->
                    <a href="{{ route('filament.admin.auth.login') }}" class="group inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-blue-900 to-indigo-900 hover:from-blue-800 hover:to-indigo-800 text-white text-xs sm:text-sm font-semibold shadow-md shadow-blue-950/15 hover:shadow-lg hover:shadow-blue-900/25 transition-all duration-200 transform hover:-translate-y-0.5">
                        <svg class="w-4 h-4 text-amber-400 group-hover:rotate-12 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                        <span>Login Admin</span>
                    </a>
                </div>

            </div>
        </div>
    </header>
