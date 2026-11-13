<div class="fi-simple-page">
    <section class="grid auto-cols-fr gap-y-6" aria-label="Login administrator">
        <img
            src="{{ asset('images/logo-bbpmp-jatim.png') }}"
            alt="Kemendikdasmen — BBPMP Provinsi Jawa Timur"
            style="display:block;width:100%;height:104px;object-fit:cover;object-position:center"
        >

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, scopes: $this->getRenderHookScopes()) }}

        <x-filament-panels::form id="form" wire:submit="authenticate">
            {{ $this->form }}
            <div class="grid gap-y-3">
                <x-filament-panels::form.actions
                    :actions="$this->getCachedFormActions()"
                    :full-width="$this->hasFullWidthFormActions()"
                />

                <a
                    href="{{ route('dashboard') }}"
                    class="fi-btn relative grid-flow-col items-center justify-center font-medium outline-none transition duration-75 focus-visible:ring-2 rounded-lg gap-2 px-3 py-2 text-sm inline-grid w-full shadow-sm bg-white hover:bg-gray-50 text-slate-800 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800 focus-visible:ring-gray-400"
                    style="border: 1px solid #334155;"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>Kembali ke Halaman Utama</span>
                </a>
            </div>
        </x-filament-panels::form>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}
    </section>
    <x-filament-actions::modals />
</div>
