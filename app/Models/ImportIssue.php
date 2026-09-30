<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportIssue extends Model
{
    protected $guarded = ['id'];

    public function getExplanationAttribute(): string
    {
        return match ($this->code) {
            'MISSING_STATUS', 'UNKNOWN_STATUS' => "Data pada kolom status 'Tidak Tersedia', silahkan lengkapi pada file Excel. Pegawai tetap dapat disimpan dengan status '-'.",
            'NON_NIP_TEXT_PPNPN' => 'Kolom NIP berisi keterangan pekerjaan. Pegawai PPNPN tetap dapat disimpan tanpa NIP. Kosongkan kolom NIP pada Excel jika tidak memiliki NIP.',
            'UNMATCHED_SUPPLEMENTAL_PERSON' => 'Nama atau NIP di lembar tambahan belum cocok dengan DUK Pegawai. Samakan penulisannya pada kedua lembar. Data pelengkap ini dilewati; pegawai dalam DUK tetap dapat disimpan.',
            'AMBIGUOUS_SUPPLEMENTAL_PERSON' => 'Nama di lembar tambahan muncul lebih dari sekali atau cocok dengan beberapa pegawai. Lengkapi NIP agar dapat dicocokkan. Data pelengkap ini belum digunakan.',
            'SUPPLEMENTAL_HEADER_NOT_FOUND' => 'Judul kolom pada lembar tambahan belum dikenali. Sesuaikan dengan template. Data pada DUK Pegawai tetap dapat disimpan.',
            'INVALID_NIP' => 'NIP harus berisi 18 angka. Cocokkan dengan dokumen kepegawaian dan masukkan kembali sebagai teks pada Excel jika angkanya berubah.',
            'MISSING_NIP' => 'NIP belum diisi. Pegawai tetap dapat disimpan; lengkapi NIP yang benar pada Excel.',
            'MISSING_NAME' => 'Nama pegawai atau jabatan belum diisi. Lengkapi pada baris yang ditunjukkan.',
            'DUPLICATE_NIP', 'DUPLICATE_IDENTITY' => 'Pegawai atau jabatan tercatat lebih dari sekali. Periksa baris yang ditunjukkan dan hapus duplikat pada Excel.',
            'INVALID_NUMBER' => 'Isian jumlah harus berupa angka bulat. Perbaiki kolom pada baris yang ditunjukkan.',
            'INVALID_GENDER' => 'Jenis kelamin belum dikenali. Gunakan L untuk laki-laki atau P untuk perempuan.',
            'VALUE_TOO_LONG' => 'Ada isian yang terlalu panjang. Ringkas isian pada baris ini menjadi maksimal 255 karakter.',
            'EMPTY_DATASET' => 'Tidak ada data yang dapat disimpan. Pastikan file Excel berisi data sesuai template.',
            default => $this->message,
        };
    }

    protected function casts(): array
    {
        return ['row_payload' => 'array'];
    }

    public function batch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
