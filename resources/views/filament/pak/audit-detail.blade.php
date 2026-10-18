<div class="pak-activity-detail">
    <style>
        .pak-activity-detail{line-height:1.6;overflow-wrap:anywhere}
        .pak-activity-meta{display:grid;grid-template-columns:1fr;gap:1.25rem;padding-bottom:1.5rem;border-bottom:1px solid #e4e4e7}
        .pak-activity-detail dt,.pak-change-label,.pak-change-column>span{color:#62626b;font-size:.875rem}
        .pak-activity-detail dd{font-weight:600;margin-top:.2rem}
        .pak-activity-detail h3{font-weight:600;font-size:1rem;margin:1.25rem 0 .35rem}
        .pak-activity-description{color:#62626b;margin-bottom:1rem}
        .pak-change-card{border:1px solid #e4e4e7;border-radius:.75rem;overflow:hidden}
        .pak-change-row,.pak-change-reason{padding:1rem 1.25rem}
        .pak-change-row+.pak-change-row,.pak-change-reason{border-top:1px solid #e4e4e7}
        .pak-change-label{font-weight:600;margin-bottom:.5rem}
        .pak-change-values{display:grid;grid-template-columns:1fr;gap:.75rem}
        .pak-change-column p{font-weight:500;white-space:pre-wrap}
        .pak-change-arrow{display:none;color:#92929b;align-self:center;text-align:center}
        .pak-change-narrative{display:flex;align-items:flex-start;gap:.65rem;margin:1.5rem 0}
        .pak-change-narrative svg{flex-shrink:0;margin-top:.2rem}
        .pak-activity-technical{border-top:1px solid #e4e4e7;padding-top:1rem;font-size:.875rem}
        .pak-activity-technical summary{cursor:pointer;color:#62626b}
        .pak-activity-technical summary:focus-visible{outline:2px solid #059669;outline-offset:3px}
        .pak-activity-technical pre{white-space:pre-wrap;overflow-wrap:anywhere;margin-top:.75rem;font-size:.75rem}
        @media(min-width:640px){.pak-activity-meta{grid-template-columns:repeat(2,minmax(0,1fr))}.pak-change-values{grid-template-columns:1fr 2rem 1fr}.pak-change-arrow{display:block}}
        .dark .pak-activity-detail dt,.dark .pak-change-label,.dark .pak-change-column>span,.dark .pak-activity-description,.dark .pak-activity-technical summary{color:#a1a1aa}
        .dark .pak-activity-meta,.dark .pak-change-card,.dark .pak-change-row,.dark .pak-change-reason,.dark .pak-activity-technical{border-color:#3f3f46}
    </style>
    <dl class="pak-activity-meta">
        <div><dt>Waktu Aktivitas</dt><dd>{{ $detail['time'] }}</dd></div>
        <div><dt>Pengguna</dt><dd>{{ $detail['actor'] }}</dd></div>
        <div><dt>Jenis Aktivitas</dt><dd>{{ $detail['activity'] }}</dd></div>
        <div><dt>Objek Data</dt><dd>{{ $detail['object'] }}</dd></div>
    </dl>
    <h3>Rincian Perubahan Data</h3>
    <p class="pak-activity-description">Berikut perubahan data yang tercatat pada aktivitas ini.</p>
    <div class="pak-change-card">
        @forelse ($detail['changes'] as $change)
            <div class="pak-change-row">
                <p class="pak-change-label">{{ $change['label'] }}</p>
                <div class="pak-change-values">
                    <div class="pak-change-column"><span>Sebelum</span><p>{{ $change['before'] }}</p></div>
                    <span class="pak-change-arrow" aria-hidden="true">→</span>
                    <div class="pak-change-column"><span>Sesudah</span><p>{{ $change['after'] }}</p></div>
                </div>
            </div>
        @empty
            <p class="pak-change-row">Tidak ada rincian perubahan sebelum/sesudah yang tercatat.</p>
        @endforelse
        <div class="pak-change-reason"><p class="pak-change-label">Alasan Perubahan</p><p>{{ $detail['reason'] }}</p></div>
    </div>
    <p class="pak-change-narrative"><x-filament::icon icon="heroicon-o-information-circle" class="h-5 w-5" aria-hidden="true" /><span>{{ $detail['narrative'] }}</span></p>
    <details class="pak-activity-technical">
        <summary>Lihat data teknis</summary>
        <pre>{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </details>
</div>
