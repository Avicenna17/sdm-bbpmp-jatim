<div class="space-y-2">
@error('snapshot') <p role="alert">{{ $message }}</p> @enderror
@error('case') <p role="alert">{{ $message }}</p> @enderror
@if (!$after)
<p>Jalankan “Pratinjau snapshot baru” setelah melengkapi form dan alasan. Snapshot tersimpan tidak diperbarui otomatis.</p>
@else
<table class="w-full text-sm"><thead><tr><th>Atribut</th><th>Tersimpan</th><th>Pratinjau baru</th></tr></thead><tbody>
@foreach (['name'=>'Nama','nip'=>'NIP','karpeg_number'=>'Karpeg','birth_place'=>'Tempat lahir','birth_date'=>'Tanggal lahir','gender'=>'Jenis kelamin','rank_name'=>'Pangkat','grade_code'=>'Golongan','rank_tmt'=>'TMT pangkat','position_name'=>'Jabatan utama','position_type'=>'Jenis jabatan','position_tmt'=>'TMT jabatan','functional_position_id'=>'ID JF','functional_position_code'=>'Kode JF','functional_position_name'=>'JF','functional_level_id'=>'ID jenjang','functional_level_code'=>'Kode jenjang','functional_category_code'=>'Kode kategori','functional_category_name'=>'Kategori','functional_level_name'=>'Jenjang','institution_name'=>'Instansi historis','work_unit'=>'Unit historis'] as $key=>$label)
<tr><td class="p-2">{{ $label }}</td><td>{{ $before[$key] ?? 'Belum tersedia' }}</td><td>{{ $after[$key] ?? 'Belum tersedia' }}</td></tr>
@endforeach
</tbody></table>
<details>
<summary>Bandingkan provenance dan data yang belum tersedia</summary>
<p>Sumber tersimpan</p>
<pre class="whitespace-pre-wrap">{{ json_encode($before['snapshot_payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
<p>Sumber pratinjau</p>
<pre class="whitespace-pre-wrap">{{ json_encode($after['snapshot_payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
</details>
<p>Draft belum merupakan hasil perhitungan. Data yang belum tersedia tetap kosong.</p>
@endif
</div>