<x-filament-panels::page>
<style>.template-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px}.template-input{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:8px;color:#172b35;background:white}.template-scroll{overflow:auto}.template-table{width:100%;border-collapse:collapse;font-size:13px}.template-table td,.template-table th{padding:10px;border:1px solid #e2e8f0;text-align:left}.template-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.template-note{font-size:13px;color:#64748b}
select.template-input{background-repeat:no-repeat!important;background-position:right 12px center!important;background-size:16px 16px!important;padding-right:38px;appearance:none}
.template-check{display:flex;align-items:flex-start;gap:10px;margin:10px 0}.template-check input{flex-shrink:0;margin-top:3px;border-radius:4px}.template-control{display:block;margin-bottom:12px}.template-control .template-note{display:block;margin-top:4px}
.template-color{display:flex;align-items:center;gap:10px;margin-top:6px}.template-color input[type=color]{width:48px;height:36px;padding:3px;border:1px solid #cbd5e1;border-radius:10px;background:white;cursor:pointer}.template-color input::-webkit-color-swatch-wrapper{padding:0}.template-color input::-webkit-color-swatch{border:0;border-radius:6px}.template-color input::-moz-color-swatch{border:0;border-radius:6px}
.template-add{margin-top:16px;padding:18px;border:1px solid #cbd5e1;border-radius:12px;max-width:640px}.template-add label{display:block;margin-bottom:12px}
.template-icon-button{border:1px solid #cbd5e1!important;border-radius:8px;min-width:36px;min-height:36px;margin:0!important}.template-icon-button:disabled{opacity:.4}
</style>
<x-filament::section heading="Atur format file import">
<p>Ubah judul, nama, urutan, warna, dan lebar kolom. Kegunaan kolom tetap sama meskipun namanya berubah. Kolom wajib tidak dapat dihapus.</p>
<p class="template-note">Perubahan disimpan sebagai draft. Unduhan baru berubah setelah versi diaktifkan. Data historis tidak dihapus.</p>
<details style="margin-top:12px"><summary style="color:#059669;cursor:pointer;font-weight:600">Panduan pengelolaan template</summary><ol style="padding:16px;list-style:decimal"><li>Pilih template, lalu sesuaikan draft dan preview.</li><li>Data standar tetap mengikuti aturan validasi sistem. Jangan mengganti nama untuk mengubah kegunaannya.</li><li>Kolom tambahan hanya untuk dokumentasi dan ekspor, tidak otomatis mengubah dashboard.</li><li>Pensiun dan proyeksi kebutuhan menggunakan jenis data serta tahun; setiap jenis/tahun hanya boleh satu kali.</li><li>Periksa template, simpan draft, lalu aktifkan dari riwayat versi.</li><li>File lama tetap dapat memakai versi lama. Jika judul di Excel diubah, pilih versinya dan isi pemetaan pada halaman import.</li></ol></details>
</x-filament::section>
<div class="template-actions">@foreach($templates as $item)<x-filament::button wire:click="open({{ $item->id }})" :color="$item->id === $templateId ? 'primary':'gray'">{{ $item->name }}</x-filament::button>@endforeach</div>
@if($errors->any())<x-filament::section><ul role="alert">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-filament::section>@endif
<div id="template-draft-editor"></div>
<x-filament::section heading="Pengaturan draft" description="Mengedit versi lama akan menghasilkan versi baru saat disimpan. Pergantian template/versi akan membuang perubahan yang belum disimpan.">
<fieldset @disabled(!\Illuminate\Support\Facades\Gate::allows('template.manage'))>
<div class="template-grid"><label>Judul dokumen<input class="template-input" wire:model.live.debounce.500ms="definition.title"></label><label>Keterangan<input class="template-input" wire:model.live.debounce.500ms="definition.subtitle"></label></div>
<div class="template-scroll" style="margin-top:16px"><table class="template-table"><thead><tr><th>Judul Kolom</th><th>Jenis data kolom</th><th>Wajib diisi</th><th>Tampilan</th><th>Urutan</th></tr></thead><tbody>
@foreach($definition['columns'] as $i=>$column)
<tr wire:key="column-{{ $templateId }}-{{ $column['key'] }}-{{ $i }}"><td style="min-width:240px"><input class="template-input" wire:model.live.debounce.500ms="definition.columns.{{ $i }}.label"><p class="template-note">{{ $fields[$column['key']]??(str_starts_with($column['key'],'extra:')?'Data tambahan':str_replace(['projection:','RETIREMENT:','REQUIREMENT:'],['','Pensiun ','Proyeksi kebutuhan '],$column['key'])) }} {{ in_array($column['key'],$required)?'· Kolom wajib':'' }}</p></td><td>@if(str_starts_with($column['key'],'extra:'))<label>Jenis data yang diisi<select class="template-input" wire:model.live="definition.columns.{{ $i }}.type"><option value="text">Teks</option><option value="number">Angka</option><option value="date">Tanggal YYYY-MM-DD</option><option value="choice">Pilihan</option></select></label><p class="template-note">Menentukan isi yang diterima: teks, angka, tanggal, atau pilihan yang Anda tentukan.</p>@if($column['type']==='choice')<textarea class="template-input" placeholder="Satu pilihan per baris" wire:model="definition.columns.{{ $i }}.choices"></textarea>@endif @else <label>Jenis data yang diisi<input class="template-input" disabled value="{{ str_starts_with($column['key'],'projection:')?'Angka':'Aturan data standar' }}"></label> @endif
@if(in_array($column['key'],$pendingKeys))
<label class="template-control" style="margin-top:12px">Kegunaan kolom baru<select class="template-input" wire:change="changePurpose({{ $i }}, $event.target.value)">
<option value="extra" @selected(str_starts_with($column['key'],'extra:'))>Data tambahan</option>
@foreach($fields as $key=>$label)@if($key===$column['key'] || !in_array($key,array_column($definition['columns'],'key')))<option value="{{ $key }}" @selected($key===$column['key'])>Data standar: {{ $label }}</option>@endif@endforeach
@if($template->source==='POSITION_REQUIREMENT')
@foreach(['RETIREMENT'=>'Pensiun','REQUIREMENT'=>'Proyeksi kebutuhan'] as $metric=>$label)
@php
$availableYear = (int) now()->year;
while(in_array('projection:'.$metric.':'.$availableYear,array_column($definition['columns'],'key')) && 'projection:'.$metric.':'.$availableYear !== $column['key']) { $availableYear++; }
$optionKey = str_starts_with($column['key'],'projection:'.$metric.':') ? $column['key'] : 'projection:'.$metric.':'.$availableYear;
@endphp
<option value="{{ $optionKey }}" @selected($optionKey===$column['key'])>Data tahunan: {{ $label }}</option>
@endforeach
@endif
</select><span class="template-note">Pilih informasi yang akan disimpan dalam kolom ini.</span></label>
@if(str_starts_with($column['key'],'projection:'))<label>Tahun<input class="template-input" type="number" min="1900" max="2199" value="{{ explode(':',$column['key'])[2] }}" wire:change="changePurpose({{ $i }}, '{{ implode(':',array_slice(explode(':',$column['key']),0,2)) }}:' + $event.target.value)"></label>@endif
@endif</td><td><label class="template-check"><input type="checkbox" wire:model="definition.columns.{{ $i }}.required" @disabled(in_array($column['key'],$required))><span class="template-note">Wajib terisi pada setiap baris</span></label>@if(in_array($column['key'],$required))<p class="template-note">Wajib untuk template ini; tidak dapat dinonaktifkan.</p>@endif</td><td style="min-width:250px">
<label class="template-control">Warna latar judul kolom<span class="template-color"><input type="color" wire:model.live="definition.columns.{{ $i }}.color"><span class="template-note">Pilih warna</span></span></label>
<label class="template-control">Lebar kolom di Excel<input type="number" min="8" max="80" class="template-input" wire:model.live="definition.columns.{{ $i }}.width"><span class="template-note">Ukuran kolom (8–80). Angka lebih besar membuat kolom lebih lebar.</span></label>
<label class="template-check"><input type="checkbox" wire:model.live="definition.columns.{{ $i }}.bold"><span>Tebalkan teks judul kolom</span></label>
<label class="template-control">Posisi teks judul kolom<select class="template-input" wire:model.live="definition.columns.{{ $i }}.align"><option value="left">Rata kiri</option><option value="center">Rata tengah</option><option value="right">Rata kanan</option></select><span class="template-note">Mengatur perataan teks pada header Excel.</span></label><x-filament::button color="gray" wire:click="applyAppearanceToAll({{ $i }})">Terapkan ke semua kolom</x-filament::button><p class="template-note" style="margin-top:6px">Salin warna, lebar, tebal, dan posisi teks kolom ini ke seluruh kolom draft.</p></td><td><div class="template-actions"><x-filament::icon-button class="template-icon-button" icon="heroicon-o-arrow-left" label="Geser kolom ke kiri" color="gray" wire:click="moveColumn({{ $i }}, -1)" :disabled="$i===0" /><x-filament::icon-button class="template-icon-button" icon="heroicon-o-arrow-right" label="Geser kolom ke kanan" color="gray" wire:click="moveColumn({{ $i }}, 1)" :disabled="$loop->last" />@if(!in_array($column['key'],$required))<x-filament::button color="danger" wire:click="removeColumn({{ $i }})" wire:confirm="{{ in_array($column['key'],$pendingKeys) ? 'Batalkan penambahan kolom ini?' : 'Hapus kolom dari draft ini? Data historis tetap tersimpan.' }}">{{ in_array($column['key'],$pendingKeys) ? 'Batal' : 'Hapus' }}</x-filament::button>@endif</div></td></tr>
@endforeach
</tbody></table></div>
@can('template.manage')
<div class="template-actions" style="margin-top:16px"><x-filament::button wire:click="startColumn" color="gray">Tambah kolom</x-filament::button><x-filament::button wire:click="check" color="gray">Periksa template</x-filament::button><x-filament::button wire:click="save">Simpan draft</x-filament::button>@if($sampleBackup)<x-filament::button color="danger" wire:click="cancelSample">Batal</x-filament::button>@endif</div>
@endcan
</fieldset>
</x-filament::section>
<x-filament::section id="template-preview" style="scroll-margin-top:100px" x-data="{}" x-on:template-version-loaded.window="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'start' }))" heading="Preview template" description="Bandingkan template yang digunakan dengan perubahan draft. Preview draft diperbarui otomatis saat Anda mengedit. Hasil uji menampilkan maksimal 5 baris contoh file. Data contoh tidak disimpan sebagai data pegawai.">
@foreach(['active'=>$activeDefinition, 'draft'=>$definition] as $previewStatus=>$preview)
<div style="padding:16px 0;{{ $previewStatus==='draft'?'border-top:1px solid #e2e8f0;margin-top:20px':'' }}" wire:key="preview-{{ $previewStatus }}">
<div style="display:flex;justify-content:flex-end;margin-bottom:16px"><x-filament::badge :color="$previewStatus==='active'?'success':'gray'">{{ $previewStatus==='active'?'Aktif / Sedang Digunakan':'Draft / Masih dalam Proses' }}</x-filament::badge></div>

<h3 style="text-align:center;font-weight:bold">{{ $preview['title'] }}</h3><p style="text-align:center">{{ $preview['subtitle']??'' }}</p>
<div class="template-scroll"><table class="template-table"><tr>@foreach($preview['columns'] as $c)<th style="background:{{ preg_match('/^#[0-9a-fA-F]{6}$/',$c['color'])?$c['color']:'#E2EFDA' }};min-width:{{ max(8,min(80,(float)$c['width']))*7 }}px;text-align:{{ in_array($c['align'],['left','center','right'])?$c['align']:'left' }};font-weight:{{ $c['bold']?'700':'400' }}">{{ $c['label'] }}</th>@endforeach</tr>@if($previewStatus==='draft' && $sampleRows)@foreach($sampleRows as $sample)<tr>@foreach($preview['columns'] as $c)<td>{{ $sample[$c['key']]??'' }}</td>@endforeach</tr>@endforeach @else<tr>@foreach($preview['columns'] as $c)<td>Contoh {{ $c['label'] }}</td>@endforeach</tr>@endif</table></div>
</div>
@endforeach
</x-filament::section>
@can('template.manage')<x-filament::section heading="Uji contoh file" description="Pilih file, tunggu unggahan selesai, lalu klik Uji file contoh. Pemeriksaan tidak berjalan otomatis. Kolom wajib harus tersedia; kolom opsional boleh tidak ada. Pemeriksaan ini memeriksa struktur, bukan kelengkapan isi setiap baris. Jika lolos, judul, susunan dan tampilan kolom dimuat ke draft; maksimal 5 baris isi file ditampilkan sebagai contoh. Simpan draft untuk menyimpan formatnya, atau pilih Batal untuk memulihkan draft sebelumnya."><div x-data="{ uploading: false, uploadFailed: false }"
    x-on:livewire-upload-start="uploading = true; uploadFailed = false"
    x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-error="uploading = false; uploadFailed = true"
    x-on:livewire-upload-cancel="uploading = false"
    x-on:template-sample-loaded.window="document.getElementById('template-draft-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' })">
    <div class="template-actions">
        <input type="file" accept=".xls,.xlsx" wire:model="sampleFile" aria-label="Pilih file contoh Excel" wire:loading.attr="disabled" wire:target="testSample">
        <fieldset x-bind:disabled="uploading">
            <x-filament::button type="button" wire:key="sample-test-{{ $sampleFile ? 'ready' : 'empty' }}" :disabled="!$sampleFile" wire:click="testSample" wire:loading.attr="disabled" wire:target="testSample">Uji file contoh</x-filament::button>
        </fieldset>
    </div>
    <p x-cloak x-show="uploading" role="status">Mengunggah file, mohon tunggu…</p>
    <p x-cloak x-show="uploadFailed" role="alert">Upload belum berhasil. Pilih ulang file Excel dan tunggu hingga muncul File siap diuji.</p>
    @if($sampleFile)<p x-show="!uploading" class="template-note" role="status">File siap diuji: <strong>{{ $sampleFile->getClientOriginalName() }}</strong>. Klik Uji file contoh untuk melanjutkan.</p>@endif
    @error('sampleFile')<p role="alert" style="color:#dc2626">{{ $message }}</p>@enderror
    <p wire:loading wire:target="testSample" role="status">Memeriksa struktur file…</p>
</div>@if($sampleResult)<p>{{ $sampleResult['rows'] }} baris terbaca. {{ count($sampleResult['errors']) }} kesalahan.</p><ul>@foreach($sampleResult['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>@endif</x-filament::section>@endcan
<x-filament::section heading="Riwayat versi" description="Aktivasi memakai draft yang sudah disimpan, bukan perubahan editor yang belum disimpan."><div class="template-scroll"><table class="template-table"><thead><tr><th>Versi</th><th>Status</th><th>Diperbarui</th><th>Tindakan</th></tr></thead><tbody>@foreach($versions as $v)<tr><td>{{ $v->number }}</td><td><div style="display:inline-flex"><x-filament::badge :color="$template->active_version_id===$v->id?'success':($v->activated_at?'info':'gray')">{{ $template->active_version_id===$v->id?'Aktif':($v->activated_at?'Arsip':'Draft') }}</x-filament::badge></div></td><td>{{ $v->updated_at->format('d/m/Y H:i') }}</td><td><div class="template-actions"><x-filament::icon-button class="template-icon-button" color="gray" icon="heroicon-o-pencil-square" label="Lihat / Edit versi {{ $v->number }}" tooltip="Lihat / Edit" wire:click="loadVersion({{ $v->id }})" /><x-filament::icon-button class="template-icon-button" color="gray" icon="heroicon-o-arrow-down-tray" label="Unduh versi {{ $v->number }}" tooltip="Unduh" tag="a" href="{{ route('templates',['source'=>$template->source==='PERSONNEL_DUK'?'personnel':'positions','version_id'=>$v->id]) }}" />@can('template.manage')@if($template->active_version_id!==$v->id)<x-filament::button wire:click="activate({{ $v->id }})" wire:confirm="Aktifkan versi tersimpan ini? Unduhan berikutnya memakai susunan ini. Data historis tetap tersimpan.">Aktifkan</x-filament::button>@endif@endcan</div></td></tr>@endforeach</tbody></table></div></x-filament::section>
</x-filament-panels::page>
