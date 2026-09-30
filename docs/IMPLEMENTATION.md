# Catatan implementasi

## Batas aplikasi

- Laravel 12 dan Filament 3.3, satu panel `/admin`.
- Dashboard publik memakai Blade, Tailwind dan Chart.js dengan bundel lokal. Filter utama dan pencarian memperbarui agregat lewat GET tanpa reload halaman; filter lanjutan diterapkan melalui tombol. Rincian angka tetap tersedia dalam tabel.
- PhpSpreadsheet digunakan langsung untuk XLS/XLSX. File DUK wajib memiliki sheet `DUK PEGAWAI`; template tersedia setelah login.
- Migrasi mengimplementasikan model master + snapshot. `name_at_period` dan `nip_at_period` ditambahkan untuk mempertahankan identitas historis pada export.
- Cache dashboard belum diaktifkan karena dataset kecil. Setiap request membaca agregat terbaru, sehingga tidak ada cache lama setelah commit/publish.

## Keputusan publikasi

Periode baru hanya dapat dipublikasikan setelah kedua sumber mempunyai snapshot. Commit menggunakan transaksi dan lock baris periode. Revisi periode published mempertahankan publikasi dan mengganti satu sumber secara atomik; hanya permission `period.revise` (Super Admin default) yang dapat melakukannya. Ini mengikuti opsi MVP pada database.md §16, bukan penyimpanan versi draft terpisah.

Preview menyimpan nomor revision periode. Commit preview lama ditolak jika commit/publish lain sudah mengubah revision. File identik dengan batch aktif menjadi no-op; file identik dengan batch historis boleh diimport kembali sebagai rollback. Histori batch tidak dihapus.

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
