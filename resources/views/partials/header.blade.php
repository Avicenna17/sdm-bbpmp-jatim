    <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-slate-200/80 shadow-2xs transition-all">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between gap-2 sm:gap-4 min-h-[3.75rem] py-2 sm:py-0 sm:h-20">

                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 sm:gap-3.5 min-w-0 group focus:outline-none">
                    <div class="flex-shrink-0 w-9 h-9 sm:w-12 sm:h-12 md:w-13 md:h-13 rounded-xl p-0.5 flex items-center justify-center">
                        <div class="w-full h-full bg-white rounded-[10px] flex items-center justify-center">
                            <img src="{{ asset('logo.png') }}" alt="Logo BBPMP Jatim" class="h-9 sm:h-12 md:h-14 w-auto object-contain group-hover:scale-105 transition-transform duration-200" />
                        </div>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-[11px] min-[360px]:text-xs sm:text-base lg:text-lg font-bold text-slate-900 leading-tight tracking-tight">
                            PORTAL DATA KEPEGAWAIAN BBPMP
                        </h1>
                        <p class="text-xs text-slate-500 font-medium hidden md:block truncate">
                            Sistem Informasi DUK & Peta Kebutuhan Jabatan (ABK)
                        </p>
                    </div>
                </a>

                <div class="flex items-center gap-2.5 sm:gap-4 flex-shrink-0">
  
                    <div class="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold shadow-2xs whitespace-nowrap">
                        <span class="relative flex h-2 w-2 flex-shrink-0">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        <span data-public-period>Periode • {{ $period?->label ?? 'Belum dipublikasikan' }}</span>
                    </div>

                    <a href="{{ route('filament.admin.auth.login') }}" class="group inline-flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-4 py-1.5 sm:py-2.5 rounded-xl bg-gradient-to-r from-blue-900 to-indigo-900 hover:from-blue-800 hover:to-indigo-800 text-white text-[11px] sm:text-sm font-semibold shadow-md shadow-blue-950/15 hover:shadow-lg hover:shadow-blue-900/25 transition-all duration-200 transform hover:-translate-y-0.5 whitespace-nowrap flex-shrink-0">
                        <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-amber-400 flex-shrink-0 group-hover:rotate-12 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                        <span>Login Admin</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="md:hidden bg-emerald-50/85 border-t border-emerald-200/70">
            <div class="max-w-7xl mx-auto px-3 sm:px-6 py-1.5 flex items-center justify-center gap-2 text-[11px] font-semibold text-emerald-800">
                <span class="relative flex h-2 w-2 flex-shrink-0">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                </span>
                <span data-public-period class="truncate">Periode • {{ $period?->label ?? 'Belum dipublikasikan' }}</span>
            </div>
        </div>
    </header>
