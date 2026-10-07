# Dashboard Monitoring SDM BBPMP Jawa Timur

Implementasi Laravel 12 + Filament 3.3 + MySQL 8 berdasarkan [PRD](docs/prd.md) dan [database](docs/database.md).

## Menjalankan di Laragon / Windows

PHP 8.2 dengan ekstensi pdo_mysql, intl, mbstring, gd, zip, xml, fileinfo diperlukan.

```powershell
composer install
# Hanya untuk checkout baru yang belum mempunyai .env:
Copy-Item .env.example .env
php artisan key:generate
```

Isi koneksi `.env` sesuai MySQL Workbench. Workbench adalah client; service MySQL harus aktif.

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sdm_bbpmp_jatim
DB_USERNAME=root
DB_PASSWORD="isi_password_mysql_Anda"
```

```powershell
php artisan sdm:setup
php artisan filament:assets
php artisan sdm:admin email@instansi.go.id
pnpm install --frozen-lockfile
pnpm run build
php artisan serve
```

`sdm:admin` meminta password melalui prompt tersembunyi (minimal 12 karakter). Tidak ada akun/password default. `sdm:setup` membuat database jika belum ada, menjalankan migrasi, dan menyiapkan role. Perintah tidak menghapus data yang sudah ada; seeder mengembalikan permission tiga role bawaan sesuai PRD.

- Dashboard publik: http://127.0.0.1:8000/
- Panel admin: http://127.0.0.1:8000/admin
- Untuk virtual host Laragon, DocumentRoot harus mengarah ke folder `public` proyek, bukan akar repository.
- Build frontend diperlukan saat instalasi awal dan setelah perubahan tampilan. Server Vite tidak perlu berjalan setelah build selesai.

## Alur penggunaan

1. Login sebagai Super Admin atau Admin SDM.
2. Pada **Periode dan Import Data**, pilih tab **DUK Pegawai**, buat/pilih bulan, unggah DUK, periksa preview, lalu **Simpan data** dan **Publikasikan DUK**.
3. Pada tab **Peta Jabatan / Kebutuhan**, unggah file tanpa memilih bulan, periksa preview, simpan versi baru, lalu **Publikasikan Peta Jabatan** saat siap.
4. Kedua sumber dapat dipublikasikan secara mandiri. DUK publik terbaru berlabel **Aktif di dashboard**; periode sebelumnya menjadi **Arsip** dan tetap dapat dilihat/diunduh.
5. **Export Data** menyediakan periode DUK dan versi Peta Jabatan yang pernah dipublikasikan. Nomor versi Peta Jabatan dimulai dari 1, khusus untuk impor Peta Jabatan yang berhasil disimpan.

Import ulang DUK mengganti snapshot bulan tersebut; histori bulan lain tetap ada. Revisi DUK terpublikasi memerlukan permission `period.revise`. Peta Jabatan menyimpan snapshot per versi: versi publik sebelumnya tetap berlaku hingga versi pengganti dipublikasikan. File identik dengan snapshot tersimpan terakhir menjadi no-op.

Panduan migrasi untuk instalasi yang sudah berjalan tersedia pada [publikasi DUK dan Peta Jabatan](docs/publikasi-duk-dan-peta-jabatan.md).

Template XLSX tersedia di menu import. Kolom NIP harus disimpan sebagai teks 18 digit. Nama/NIP tidak dikirim oleh dashboard publik; export detail hanya untuk pengguna yang memiliki permission.

## Pengujian

### Pemulihan migrasi awal MySQL

Jika instalasi awal terhenti dengan error 1059 (nama foreign key terlalu panjang), gunakan kode migrasi terbaru yang memakai nama `projection_snapshot_fk`. MySQL dapat meninggalkan tabel kosong dari migrasi yang gagal. Jangan menggunakan `migrate:fresh` untuk memperbaikinya karena perintah tersebut menghapus seluruh tabel.

```powershell
php artisan sdm:repair-monitoring-migration
php artisan sdm:repair-monitoring-migration --apply
```

Perintah pertama hanya memeriksa. Perintah kedua membuat ulang hanya tujuh tabel monitoring dari migrasi yang belum selesai, lalu menjalankan migrasi pending. Pemulihan otomatis ditolak jika salah satu tabel monitoring berisi data atau lingkungan produksi. Tabel pengguna, cache, jobs, dan catatan migrasi yang berhasil tetap dipertahankan. Jika migrasi monitoring sudah berhasil, perintah tidak mengubah apa pun. Jalankan saat aplikasi belum digunakan untuk import.

```powershell
php artisan test
php vendor/bin/pint --test
```

Test suite memakai SQLite in-memory yang terpisah dari database aplikasi. Mencakup transaksi rollback, replacement snapshot, histori, duplikasi NIP/checksum, fallback identitas, status UNKNOWN, tahun dinamis, header gabungan XLS, enrichment, stale preview, publish, export, dan akses admin/publik.

## Deployment

- Gunakan MySQL 8 dan database/user khusus aplikasi; jangan gunakan root di produksi.
- Atur APP_ENV=production, APP_DEBUG=false, APP_URL HTTPS, SESSION_SECURE_COOKIE=true.
- Jalankan `php artisan migrate --force`, `php artisan filament:assets`, dan `php artisan optimize`.
- Direktori `storage` dan `bootstrap/cache` harus dapat ditulis; upload berada di `storage/app/private`.
- PHP web: upload_max_filesize minimal 16M, post_max_size minimal 20M; sesuaikan memory_limit dan timeout setelah benchmark workbook nyata.
- Siapkan backup harian serta retensi. Skrip dan batas implementasi tersedia pada [catatan implementasi](docs/IMPLEMENTATION.md).


Untuk menjalankan pengujian opsional dengan file DUK September tersebut, set environment variable `SDM_DUK_WORKBOOK` ke path workbook lalu jalankan `php artisan test`. Test ini memakai database in-memory, tidak mengubah file sumber atau snapshot aplikasi.

## Dashboard publik hasil integrasi UI tim

Tampilan dashboard mengadaptasi header, hero biru, kartu ringkasan, grafik, dan rekap dari `bbpmp_dashboard_ui.zip`. Data contoh dan tahun statis diganti oleh `DashboardQuery` pada periode terpublikasi. File proyek, model, dan migrasi dari ZIP tidak menggantikan backend aplikasi. Salinan referensi ZIP tidak diperlukan untuk menjalankan proyek.

Frontend menggunakan Tailwind dan Chart.js yang dibundel lokal melalui Vite. Untuk build pada komputer/deployment lain:

```powershell
pnpm install --frozen-lockfile
pnpm run build
```

Sertakan hasil `public/build` saat deployment. Setelah mengubah Blade/CSS/JavaScript, jalankan build kembali. Lockfile disertakan agar versi dependensi konsisten.

Filter kepegawaian memengaruhi total dan grafik pegawai. Filter pencarian/jenis jabatan memengaruhi ringkasan formasi, proyeksi tahunan, dan tabel peta jabatan; DUK mengikuti periode, sedangkan Peta Jabatan memakai versi publik aktif lintas periode. Kolom tahunan mengikuti data import, nilai kosong tidak diubah menjadi nol, dan data parsial ditandai pada rincian angka. Dashboard publik tidak mengirim nama/NIP, file sumber, atau data periode draft.

## Template Import

Pengelolaan versi, editor kolom, preview, uji contoh file, serta pemetaan import dijelaskan pada [panduan Template Import](docs/TEMPLATE-IMPORT.md). Setelah memperbarui kode, jalankan migrasi sebelum membuka menu baru.


### Grafik kosong pada lingkungan lokal

Untuk `php artisan serve`, gunakan `APP_URL=http://127.0.0.1:8000` dan biarkan `ASSET_URL=` kosong di `.env`. Dengan demikian, CSS dan JavaScript Vite dilayani dari host lokal yang sedang dibuka. Jangan menyalin `ASSET_URL` produksi ke konfigurasi lokal: JavaScript bertipe module lintas domain dapat ditolak browser, meskipun CSS dan angka ringkasan masih tampil.

Setelah mengubah konfigurasi, jalankan `php artisan config:clear`, lalu `pnpm run build`. Pastikan URL script pada dashboard mengarah ke `/build/assets/` di host lokal. Di server produksi subdirektori, tetap gunakan konfigurasi server yang sesuai, misalnya `APP_URL=https://lpmp-jatim.net/sdm` dan `ASSET_URL=https://lpmp-jatim.net/sdm`, serta unggah hasil build yang dibuat dengan konfigurasi produksi tersebut. Jangan mengunggah `.env` lokal ke server.
