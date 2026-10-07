<x-filament-panels::page>

<style>.sdm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px}.sdm-label{display:block;font-size:13px;margin-bottom:8px}.sdm-input{border:1px solid #cbd5e1;border-radius:8px;padding:10px;width:100%;color:#172b35;background:white}.sdm-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:16px}.sdm-table{width:100%;font-size:13px;border-collapse:collapse}.sdm-table td,.sdm-table th{padding:12px;text-align:left;border-bottom:1px solid #e2e8f0}.sdm-scroll{overflow:auto}.sdm-muted{font-size:13px;color:#64748b}.sdm-stat{font-size:26px;font-weight:650}.sdm-error{color:#b91c1c}.sdm-warning{color:#a16207}select.sdm-input{appearance:none;background-repeat:no-repeat;background-position:right 10px center;background-size:16px 16px;padding-right:36px}</style>

@if($errors->any())<x-filament::section><ul class="sdm-error">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-filament::section>@endif

<style>
.sdm-workspaces{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #cbd5e1;gap:24px}.sdm-workspace{display:flex;align-items:center;gap:14px;text-align:left;padding:18px 4px;border-bottom:3px solid transparent;margin-bottom:-1px;color:#64748b;transition:color .15s,border-color .15s}.sdm-workspace[aria-selected="true"]{border-color:#059669;color:#047857}.sdm-workspace:hover{color:#047857}.sdm-workspace:focus-visible{outline:2px solid #059669;outline-offset:4px;border-radius:6px}.sdm-workspace-icon{width:44px;height:44px;flex-shrink:0;border:1px solid #e2e8f0;border-radius:12px;display:grid;place-items:center;background:#fff}.sdm-workspace-icon svg{width:23px;height:23px}.sdm-workspace[aria-selected="true"] .sdm-workspace-icon{background:#ecfdf5;border-color:#a7f3d0}.sdm-workspace strong{display:block;font-size:16px}.sdm-workspace small{display:block;margin-top:4px;font-size:12px;color:#64748b}.sdm-tab-panel{display:grid;gap:24px}.sdm-result{margin-top:24px;padding-top:24px;border-top:1px solid #e2e8f0}.dark .sdm-workspace-icon{background:#18181b;border-color:#3f3f46}.dark .sdm-workspace[aria-selected="true"]{color:#6ee7b7}.dark .sdm-workspace[aria-selected="true"] .sdm-workspace-icon{background:#064e3b;border-color:#059669}.dark .sdm-workspace small,.dark .sdm-muted{color:#a1a1aa}.dark .sdm-input{background:#18181b;color:#fafafa;border-color:#52525b}@media(max-width:600px){.sdm-workspaces{gap:12px}.sdm-workspace{align-items:flex-start;gap:8px;padding:14px 0}.sdm-workspace-icon{display:none}.sdm-workspace strong{font-size:14px}}
</style>
<div class="sdm-workspaces" role="tablist" aria-label="Jenis data yang dikelola" x-on:keydown.right.prevent="$event.target.closest('[role=tab]').nextElementSibling?.focus()" x-on:keydown.left.prevent="$event.target.closest('[role=tab]').previousElementSibling?.focus()">
    @foreach(['PERSONNEL_DUK'=>['DUK Pegawai','Periode pelaporan bulanan','heroicon-o-users'], 'POSITION_REQUIREMENT'=>['Peta Jabatan / Kebutuhan','Pembaruan sesuai kebutuhan','heroicon-o-building-office-2']] as $key => [$label,$description,$icon])
    <button type="button" class="sdm-workspace" role="tab" id="source-tab-{{ $key }}" aria-controls="source-panel" aria-selected="{{ $source === $key ? 'true' : 'false' }}" wire:click="selectSource('{{ $key }}')" wire:loading.attr="disabled" wire:target="selectSource,file,preview,saveImport,revalidate,publish,publishPositions">
        <span class="sdm-workspace-icon"><x-filament::icon :icon="$icon" /></span><span><strong>{{ $label }}</strong><small>{{ $description }}</small></span>
    </button>
    @endforeach
</div>
<div id="source-panel" role="tabpanel" aria-labelledby="source-tab-{{ $source }}" class="sdm-tab-panel" wire:key="source-panel-{{ $source }}">
@if($source === 'PERSONNEL_DUK')
<x-filament::section heading="Periode DUK Pegawai" description="Pilih bulan pelaporan untuk DUK Pegawai.">

<div class="sdm-grid"><div><label class="sdm-label" for="period">Pilih periode</label><select id="period" class="sdm-input" wire:model.live="periodId"><option value="">Pilih periode</option>@foreach($periods as $p)<option value="{{ $p->id }}">{{ $p->label }} · {{ $p->publicationLabel($activePeriod) }}</option>@endforeach</select></div>@can('period.create')<form wire:submit="createPeriod"><label class="sdm-label" for="month">Buat periode baru</label><div class="sdm-actions" style="margin:0"><input id="month" class="sdm-input" style="width:auto" type="month" wire:model="month" required><x-filament::button type="submit">Buat periode</x-filament::button></div></form>@endcan</div>

@if($period)<div class="sdm-grid" style="margin-top:24px"><div><span class="sdm-muted">Status periode</span><div class="sdm-stat">{{ $period->publicationLabel($activePeriod) }}</div></div><div><span class="sdm-muted">Pegawai tersimpan</span><div class="sdm-stat">{{ $period->personnel()->count() }}</div></div><div><span class="sdm-muted">Terakhir dipublikasikan</span><div class="sdm-stat">{{ $period->published_at ? $period->published_at->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB' : 'Belum dipublikasikan' }}</div></div></div><div class="sdm-actions">@can('period.publish')<x-filament::button wire:click="publish" wire:confirm="Publikasikan data periode ini ke dashboard publik?" :disabled="!$period->canPublish($activePeriod)">Publikasikan DUK</x-filament::button>@endcan<x-filament::button tag="a" href="{{ route('dashboard') }}" color="gray" target="_blank">Lihat dashboard publik ↗</x-filament::button></div>@endif

</x-filament::section>
@else
<x-filament::section heading="Status Peta Jabatan / Kebutuhan" description="Tidak terikat periode. Import baru disimpan sebagai versi yang perlu dipublikasikan.">
<div class="sdm-grid">
    <div><span class="sdm-muted">Status data</span><div class="sdm-stat">{{ !$positionState->draft_batch_id ? 'Belum ada data' : ($positionState->draft_batch_id === $positionState->published_batch_id ? 'Aktif di dashboard' : 'Siap dipublikasikan') }}</div></div>
    <div><span class="sdm-muted">Jabatan tersimpan</span><div class="sdm-stat">{{ $positionCount }}</div></div>
    <div><span class="sdm-muted">Terakhir dipublikasikan</span><div class="sdm-stat">{{ $positionState->published_at ? $positionState->published_at->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB' : 'Belum dipublikasikan' }}</div></div>
</div>
<div class="sdm-actions">
    @can('period.publish')<x-filament::button wire:click="publishPositions({{ $positionState->draft_batch_id ?? 0 }})" wire:confirm="Publikasikan Peta Jabatan tersimpan ini ke dashboard publik?" :disabled="!$positionState->draft_batch_id || $positionState->draft_batch_id === $positionState->published_batch_id">Publikasikan Peta Jabatan</x-filament::button>@endcan
    <x-filament::button tag="a" href="{{ route('dashboard') }}#duk-rekap-section" color="gray" target="_blank">Lihat dashboard publik ↗</x-filament::button>
</div>
</x-filament::section>
@endif

@if(Gate::allows('import.create') || $batch)<x-filament::section heading="Import data" description="XLS/XLSX, maksimal 15 MB. Koreksi dilakukan pada sumber kemudian import ulang.">@can('import.create')<form wire:submit="preview"><div class="sdm-grid"><div><label class="sdm-label" for="file">File Excel</label><input class="sdm-input" type="file" id="file" wire:model="file" accept=".xls,.xlsx" required><span wire:loading wire:target="file" class="sdm-muted">Mengunggah file…</span></div></div><div style="margin-top:16px"><label class="sdm-label">Versi template<select class="sdm-input" wire:model.live="templateVersionId"><option value="">Kenali otomatis</option><option value="0">Format bawaan lama (tanpa kolom tambahan)</option>@foreach($templateVersions as $v)<option value="{{ $v->id }}">Versi {{ $v->number }}{{ $v->template->active_version_id===$v->id?' (aktif)':'' }}</option>@endforeach</select></label><p class="sdm-muted">File unduhan baru membawa penanda versi. Pilih versi secara manual jika file tidak memiliki penanda. Gunakan versi template untuk perubahan nama atau penambahan kolom.</p></div>
@if($selectedTemplate)<details style="margin-top:16px"><summary>Sesuaikan pemetaan jika judul kolom Excel berbeda</summary><p class="sdm-muted">Isi nama persis kolom dalam file. Biarkan kosong jika sama dengan template. Kolom baru harus didaftarkan melalui Template Import sebelum dapat disimpan.</p><div class="sdm-grid">@foreach($selectedTemplate->definition['columns'] as $c)<label>{{ $c['label'] }}<input class="sdm-input" placeholder="{{ $c['label'] }}" wire:model="templateMapping.{{ $c['key'] }}"></label>@endforeach</div><label>Kolom yang sengaja diabaikan (satu judul per baris)<textarea class="sdm-input" wire:model="ignoredColumns"></textarea></label><p class="sdm-muted">Nilai kolom yang diabaikan tidak disimpan sebagai data terstruktur. Pastikan memang tidak diperlukan.</p></details>@endif
<div class="sdm-actions"><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="file,preview">Periksa file</x-filament::button><x-filament::button tag="a" color="gray" href="{{ route('templates', $source === 'PERSONNEL_DUK' ? ['source'=>'personnel','period_id'=>$periodId] : ['source'=>'positions']) }}">{{ $source === 'PERSONNEL_DUK' ? 'Unduh template DUK' : 'Unduh template Peta Jabatan' }}</x-filament::button></div><p wire:loading wire:target="preview" class="sdm-muted">Membaca dan memvalidasi workbook…</p></form>@endcan

@if($batch)

<div class="sdm-result" id="import-result"><h3 style="font-weight:600">Hasil pemeriksaan file</h3><p class="sdm-muted" style="margin:8px 0 20px">{{ $batch->original_filename }} · {{ $batch->status_label }}</p>

    @if($batch->template_version_id)<p>Versi template: {{ \App\Models\ImportTemplateVersion::find($batch->template_version_id)?->number }}</p><details><summary>Pemetaan kolom yang digunakan</summary><ul>@foreach($batch->summary['template_mapping']??[] as $m)<li>{{ $m['label'] }} (kolom {{ $m['column'] }}) &rarr; {{ $m['target']??$m['label'] }}</li>@endforeach</ul>@if($batch->summary['ignored_columns']??[])<p>Diabaikan: {{ implode(', ', $batch->summary['ignored_columns']) }}</p>@endif</details>@endif
    <div class="sdm-grid">

        @foreach(['total_rows'=>'Data terbaca','valid_rows'=>'Data valid','error_rows'=>'Baris perlu diperbaiki','review_warning_rows'=>'Baris dengan catatan'] as $key=>$label)

            <div><span class="sdm-muted">{{ $label }}</span><div class="sdm-stat">{{ $batch->$key }}</div></div>

        @endforeach

    </div>

    @if(collect($previewRows)->contains(fn($row) => !empty($row['extra_data'])))<details><summary>Preview data tambahan (maksimal 25 baris)</summary><table class="sdm-table"><tr><th>Baris Excel</th><th>Data tambahan</th></tr>@foreach($previewRows as $row)<tr><td>{{ $row['source_row_no'] }}</td><td>@foreach($row['extra_data']??[] as $item)<div>{{ $item['label'] }}: {{ $item['value']??'-' }}</div>@endforeach</td></tr>@endforeach</table></details>@endif
    @if($batch->status==='validated')

        <p style="margin-top:16px">Data belum disimpan. Periksa ringkasan berikut, lalu pilih <strong>Simpan data</strong>. Catatan tidak menghalangi penyimpanan.</p>

        <div class="sdm-actions">

            @foreach(['new'=>'Data baru','changed'=>'Diperbarui','unchanged'=>'Tidak berubah','removed'=>'Tidak ada pada versi baru'] as $key=>$label)

                <span>{{ $label }}: <strong>{{ $batch->summary[$key]??0 }}</strong></span>

            @endforeach

        </div>

        @can('import.commit')

        <div class="sdm-actions">

            <x-filament::button wire:click="saveImport" wire:confirm="{{ $batch->source_type === 'PERSONNEL_DUK' ? 'Ganti DUK periode '.$batch->period?->label.' dengan isi file ini?' : 'Simpan versi Peta Jabatan ini? Data publik tetap memakai versi sebelumnya sampai dipublikasikan.' }}" wire:loading.attr="disabled">

                <span wire:loading.remove wire:target="saveImport">Simpan data</span>

                <span wire:loading wire:target="saveImport">Menyimpan…</span>

            </x-filament::button>

        </div>

        @endcan

    @elseif($batch->status==='committed')

        <p style="margin-top:16px"><strong>Data berhasil disimpan</strong> pada {{ $batch->committed_at?->format('d/m/Y H:i') }} {{ $batch->source_type === 'PERSONNEL_DUK' ? 'untuk periode '.$batch->period?->label : 'sebagai versi Peta Jabatan' }}.</p>

        @if($batch->source_type==='PERSONNEL_DUK')

            @can('personnel.view')<div class="sdm-actions"><x-filament::button tag="a" :href="\App\Filament\Pages\PersonnelData::getUrl(['tableFilters'=>['reporting_period_id'=>['value'=>$batch->reporting_period_id]]])">Lihat Daftar Pegawai</x-filament::button></div>@endcan

        @else

            @can('position_requirement.view')<div class="sdm-actions"><x-filament::button tag="a" :href="\App\Filament\Pages\PositionData::getUrl()">Lihat Peta Jabatan</x-filament::button></div>@endcan
        @endif

    @else

        <p class="sdm-error" style="margin-top:16px">Data belum disimpan. Perbaiki baris yang bermasalah pada Excel, lalu unggah kembali.</p>

    @endif

    @foreach(['ERROR'=>'Perlu diperbaiki sebelum disimpan','WARNING'=>'Catatan yang dapat ditinjau'] as $severity=>$heading)

        @php($groups = $batch->review_issues->where('severity',$severity)->groupBy(fn ($issue) => $issue->code.'|'.$issue->explanation))

        @if($groups->isNotEmpty())

        <details style="margin-top:20px" @if($severity==='ERROR') open @endif>

            <summary>{{ $heading }} ({{ $batch->review_issues->where('severity',$severity)->count() }})</summary>

            <div class="sdm-scroll"><table class="sdm-table"><thead><tr><th>Lokasi di Excel</th><th>Keterangan dan Tindakan</th></tr></thead><tbody>

                @foreach($groups as $issues)

                <tr><td>@foreach($issues->groupBy('source_sheet') as $sheet=>$locations)

                    <div><strong>{{ $sheet ?: 'Lembar tidak tercatat' }}</strong>: {{ $locations->map(fn ($issue) => $issue->source_cell ?: ($issue->source_row ? 'baris '.$issue->source_row : 'seluruh lembar'))->unique()->implode(', ') }}</div>

                @endforeach</td><td>{{ $issues->first()->explanation }}</td></tr>

                @endforeach

            </tbody></table></div>

        </details>

        @endif

    @endforeach

    @if($previewRows)

    <details style="margin-top:20px"><summary>Lihat contoh data (maksimal 25 baris)</summary>

        <div class="sdm-scroll"><table class="sdm-table"><thead><tr><th>Baris</th><th>Nama / jabatan</th><th>Status</th><th>Pendidikan / kebutuhan</th></tr></thead><tbody>

        @foreach($previewRows as $row)<tr><td>{{ $row['source_row_no'] }}</td><td>{{ $row['name_at_period']??$row['position_name'] }}</td><td>{{ ($row['employment_status']??'')==='UNKNOWN' ? '-' : ($row['employment_status']??$row['requirement_status']??'—') }}</td><td>{{ $row['education_level']??$row['requirement_count']??'—' }}</td></tr>@endforeach

        </tbody></table></div>

    </details>

    @endif

    @can('import.create')@if(in_array($batch->status,['failed','validated']))

    <details style="margin-top:20px"><summary>Periksa kembali file yang sama</summary><p class="sdm-muted">Gunakan jika aplikasi telah diperbarui. Jika isi Excel berubah, unggah file yang sudah diperbaiki.</p><div class="sdm-actions"><x-filament::button color="gray" wire:click="revalidate" wire:loading.attr="disabled">Periksa ulang file</x-filament::button></div></details>

    @endif@endcan

</div>
@endif
</x-filament::section>
@endif

@can('import.view')<x-filament::section heading="Histori import" :description="$source === 'PERSONNEL_DUK' ? '30 import DUK terbaru pada periode terpilih.' : '30 import Peta Jabatan / Kebutuhan terbaru.'"><div class="sdm-scroll"><table class="sdm-table"><thead><tr><th>File</th><th>Pengunggah</th><th>Status</th><th>Waktu</th><th></th></tr></thead><tbody>@forelse($history as $item)<tr><td>{{ $item->original_filename }}<br><small>{{ $item->source_label }}</small></td><td>{{ $item->uploader->name }}</td><td>{{ $item->status_label }}</td><td>{{ $item->created_at->format('d/m/Y H:i') }}</td><td><x-filament::button size="xs" color="gray" wire:click="inspect({{ $item->id }})">Detail</x-filament::button></td></tr>@empty<tr><td colspan="5">Belum ada import.</td></tr>@endforelse</tbody></table></div></x-filament::section>@endcan

</div>
</x-filament-panels::page>
