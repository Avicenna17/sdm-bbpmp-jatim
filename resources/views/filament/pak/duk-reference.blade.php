<div class="space-y-2">
<p>Referensi published; kandidat belum terverifikasi. TMT/SK/JF harus diverifikasi terpisah.</p>
<dl>
@foreach (['period_month' => 'Periode', 'revision' => 'Revisi', 'name_at_period' => 'Nama', 'nip_at_period' => 'NIP', 'rank_name' => 'Pangkat kandidat', 'grade_code' => 'Golongan kandidat', 'position_name' => 'Jabatan kandidat', 'gender' => 'Jenis kelamin', 'placement_current' => 'Penempatan sumber (bukan unit historis otomatis)'] as $key => $label)
<dt class="font-semibold">{{ $label }}</dt><dd>{{ $reference[$key] ?? 'Belum tersedia' }}</dd>
@endforeach
</dl></div>