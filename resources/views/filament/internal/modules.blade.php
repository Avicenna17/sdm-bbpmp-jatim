<div class="pak-portal">
    <style>
        .fi-simple-main:has(.pak-portal){padding:0;box-shadow:none;border-radius:1rem;overflow:hidden}
        .pak-portal{color:#18181b}
        .pak-portal-header{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.5rem 2rem;border-bottom:1px solid #e4e4e7}
        .pak-portal-brand{display:flex;align-items:center;gap:.75rem}
        .pak-portal-brand-icon{padding:.65rem;border-radius:.65rem;background:#f4f4f5}
        .pak-portal-brand strong{display:block;font-size:1.1rem;font-weight:600}
        .pak-portal-brand p,.pak-portal-intro,.pak-portal-footer{color:#62626b;line-height:1.6}
        .pak-portal-content{padding:2rem}
        .pak-portal-content h1{font-size:1.6rem;font-weight:600;line-height:1.3;margin-bottom:.5rem}
        .pak-module-grid{display:grid;gap:1rem;grid-template-columns:1fr;margin-top:1.5rem}
        @media(min-width:768px){.pak-module-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .pak-module-card{display:flex;flex-direction:column;align-items:flex-start;gap:.75rem;padding:1.5rem;border:1px solid #e4e4e7;border-radius:.75rem;color:inherit;text-decoration:none;transition:box-shadow .15s,border-color .15s}
        .pak-module-card:hover{border-color:#71717a;box-shadow:0 4px 16px #00000008}
        .pak-module-card:focus-visible{outline:3px solid #059669;outline-offset:4px}
        .pak-module-card>svg{margin:.5rem 0 1.25rem}
        .pak-module-card h2{font-size:1.25rem;font-weight:600}
        .pak-module-card p{color:#62626b;line-height:1.6;flex:1}
        .pak-module-cta{font-weight:600;margin-top:.65rem}
        .pak-portal-footer{text-align:center;padding:0 1.5rem 1.5rem;font-size:.875rem}
        .dark .pak-portal{color:#f4f4f5}
        .dark .pak-portal-header,.dark .pak-module-card{border-color:#3f3f46}
        .dark .pak-portal-brand-icon{background:#27272a}
        .dark .pak-portal-brand p,.dark .pak-portal-intro,.dark .pak-portal-footer,.dark .pak-module-card p{color:#a1a1aa}
        @media(max-width:480px){.pak-portal-header,.pak-portal-content{padding:1.25rem}}
    </style>
    <header class="pak-portal-header">
        <div class="pak-portal-brand">
            <span class="pak-portal-brand-icon"><x-filament::icon icon="heroicon-o-building-library" class="h-6 w-6" aria-hidden="true" /></span>
            <div><strong>BBPMP Jawa Timur</strong><p>Portal Kepegawaian</p></div>
        </div>
        <x-filament-panels::user-menu />
    </header>
    <div class="pak-portal-content">
        <h1>Selamat Datang</h1>
        <p class="pak-portal-intro">Pilih modul untuk mengakses layanan kepegawaian.</p>
        <div class="pak-module-grid">
            @foreach ($modules as $module)
                <a class="pak-module-card" href="{{ $module['url'] }}" aria-labelledby="module-{{ $module['id'] }}">
                    <x-filament::icon :icon="$module['icon']" class="h-8 w-8" aria-hidden="true" />
                    <h2 id="module-{{ $module['id'] }}">{{ $module['label'] }}</h2>
                    <p>{{ $module['description'] }}</p>
                    <span class="pak-module-cta">Buka Modul <span aria-hidden="true">↗</span></span>
                </a>
            @endforeach
        </div>
    </div>
    <footer class="pak-portal-footer">BBPMP Provinsi Jawa Timur • Sistem Informasi Kepegawaian</footer>
</div>
