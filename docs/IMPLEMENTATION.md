# Catatan implementasi

## Batas aplikasi

- Laravel 12 dan Filament 3.3, satu panel `/admin`.
- Dashboard publik memakai Blade, Tailwind dan Chart.js dengan bundel lokal. Filter utama dan pencarian memperbarui agregat lewat GET tanpa reload halaman; filter lanjutan diterapkan melalui tombol. Rincian angka tetap tersedia dalam tabel.
- PhpSpreadsheet digunakan langsung untuk XLS/XLSX. File DUK wajib memiliki sheet `DUK PEGAWAI`; template tersedia setelah login.
- Migrasi mengimplementasikan model master + snapshot. `name_at_period` dan `nip_at_period` ditambahkan untuk mempertahankan identitas historis pada export.
- Cache dashboard belum diaktifkan karena dataset kecil. Setiap request membaca agregat terbaru, sehingga tidak ada cache lama setelah commit/publish.

## Keputusan publikasi

DUK dapat dipublikasikan setelah snapshot pegawai bulan tersebut tersedia. Peta Jabatan menggunakan versi independen dengan pointer versi tersimpan dan versi publik pada `position_datasets`; publikasi Peta Jabatan tidak menunggu DUK. Rincian migrasi, status aktif/arsip, dan penomoran versi ada pada [panduan publikasi](publikasi-duk-dan-peta-jabatan.md).

Commit memakai transaksi dan lock pada periode DUK atau dataset Peta Jabatan. Revision masing-masing sumber mencegah commit preview yang kedaluwarsa tanpa saling menghalangi. Revisi DUK terpublikasi tetap memerlukan `period.revise`; versi Peta Jabatan baru disimpan sebagai draft sampai dipublikasikan. Nomor versi diberikan hanya saat impor Peta Jabatan berhasil disimpan. Histori batch dan versi tidak dihapus.

## Import dan batas validasi

- NIP berupa teks 18 digit; angka notasi ilmiah atau digit rusak diblokir. ASN tanpa NIP memperoleh warning dan memakai identitas nama.
- Khusus status PPNPN, teks pekerjaan di kolom NIP disimpan sebagai keterangan (nip_note), dengan NIP kosong. Nomor berisi digit tetapi rusak tetap ditolak. Impor pegawai hanya memakai sheet DUK PEGAWAI; sheet tambahan diabaikan.
- Nama fallback hanya trim/collapse spasi/uppercase, tidak menghapus gelar secara agresif. Nama fallback ganda diblokir agar dua orang tidak tergabung.
- Status kosong/asing menjadi UNKNOWN dengan warning; tidak dianggap ASN/PPNPN.
- Issue baru mencatat sheet dan alamat sel. Jumlah baris warning dihitung berdasarkan kombinasi sheet+baris; INFO normalisasi tidak dihitung sebagai warning. Validasi ulang file tersimpan membuat batch baru dan mempertahankan histori lama.
- XLS dan XLSX dengan header datar maupun gabungan didukung. Tahun proyeksi disimpan di child table. Formula tidak dieksekusi saat membaca.
- Batas file 15 MB, 15.000 baris dan 200 kolom. PHP web perlu upload_max_filesize ≥16M dan post_max_size ≥20M. Ukuran akhir serta kapasitas RAM perlu diuji menggunakan file nyata.
- Preview menampilkan summary diff dan 25 baris pertama; semua issue tersimpan di audit. Tidak menyimpan field-level diff permanen.

## Verifikasi yang masih membutuhkan lingkungan pengguna

- Target import 10.000 baris <60 detik dan render <2 detik perlu benchmark pada server MySQL deployment.
- Backup harian disediakan sebagai skrip; penjadwalan Windows, volume terenkripsi, retensi, dan uji restore harus disiapkan operator.
- Tidak ada data pribadi nyata atau akun dengan password bawaan yang dimasukkan oleh seeder.

## Backup

Siapkan MySQL login-path melalui `mysql_config_editor set --login-path=sdm-backup --host=127.0.0.1 --user=USER_BACKUP --password`. Password dimasukkan melalui prompt, tidak ditaruh dalam argument atau repository.

Jalankan `scripts/backup-mysql.ps1 -BackupDirectory D:\BackupSDM` pada volume terenkripsi yang aksesnya dibatasi. Jadwalkan setiap hari melalui Task Scheduler. Backup SQL masih berisi data pribadi; lindungi direktori/volume dan salinan offsite. Uji restore ke database terpisah secara berkala. Raw upload tidak dihapus otomatis; tentukan retensi sesuai kebijakan organisasi.
