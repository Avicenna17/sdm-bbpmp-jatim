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
            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}
    </section>
    <x-filament-actions::modals />
</div>
