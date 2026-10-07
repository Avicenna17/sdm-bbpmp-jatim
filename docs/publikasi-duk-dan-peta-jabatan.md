# Publikasi DUK dan Peta Jabatan secara mandiri

## Perilaku

- DUK Pegawai disimpan per bulan. DUK yang sudah disimpan dapat langsung dipublikasikan tanpa Peta Jabatan.
- Peta Jabatan/Kebutuhan tidak memerlukan periode. Unggah, periksa, simpan versi baru, lalu pilih **Publikasikan Peta Jabatan**.
- Menyimpan versi Peta Jabatan tidak langsung mengganti data publik. Versi publik sebelumnya tetap berlaku sampai versi pengganti dipublikasikan.
- Peta Jabatan terbaru berlaku pada semua pilihan periode DUK. Data ini bukan rekonstruksi historis Peta Jabatan pada bulan DUK yang dipilih.
- Halaman admin Peta Jabatan menampilkan versi tersimpan terbaru. Dashboard menampilkan versi aktif. Ekspor menggunakan versi aktif sebagai pilihan awal dan menyediakan versi publik terdahulu sebagai arsip.
- Peta Jabatan tetap bisa ditampilkan dan diekspor saat belum ada DUK atau periode yang dipublikasikan.
- Riwayat impor dan snapshot versi Peta Jabatan dipertahankan. Preview lama dengan aturan periode harus diperiksa ulang sebelum disimpan.
- Hak publikasi memakai izin `period.publish` yang sudah tersedia untuk Super Admin dan Admin SDM. Publikasi versi baru Peta Jabatan adalah pembaruan rutin; tidak memerlukan izin revisi periode DUK.
- Aturan revisi DUK yang sudah dipublikasikan tetap berlaku: izin `period.revise` diperlukan dan penyimpanannya memperbarui DUK publik pada bulan tersebut.

## Penerapan pada server yang sudah berjalan

Perubahan kode lokal belum memperbarui https://lpmp-jatim.net/sdm/ secara otomatis.

1. Cadangkan database dan direktori `storage/app` sebelum pembaruan.
2. Aktifkan maintenance mode (`php artisan down`) dan unggah kode rilis ini dengan prosedur deployment proyek.
3. Jalankan `php artisan migrate --force` dari direktori proyek di server.
4. Pasang dependensi sesuai lockfile (`composer install --no-dev --optimize-autoloader` dan `pnpm install --frozen-lockfile`), jalankan `pnpm build` serta `php artisan filament:assets`. Build dapat dilakukan pada mesin build lalu hasil `public/build` disertakan dalam deployment.
5. Pertahankan `.env` server beserta `APP_KEY`; pastikan `APP_URL` dan `ASSET_URL` sesuai URL produksi. Jalankan `php artisan optimize:clear`, lalu `php artisan up`.
6. Periksa admin: versi Peta Jabatan publik dan versi tersimpan, DUK per bulan, serta ekspor masing-masing sumber.

Migrasi `2026_10_07_000100_decouple_position_dataset` tidak menghapus snapshot atau file impor lama. Migrasi memilih Peta Jabatan dari periode terpublikasi paling baru sebagai versi publik awal. Versi tersimpan diambil dari periode paling baru yang memiliki snapshot Peta Jabatan, termasuk periode yang belum dipublikasikan. Bila hanya ada Peta Jabatan draft, administrator perlu mempublikasikannya secara eksplisit.

Relasi periode pada data lama dipertahankan sebagai jejak asal; impor Peta Jabatan baru menggunakan periode kosong. Status periode DUK yang sudah berisi pegawai diubah menjadi siap dipublikasikan tanpa menunggu Peta Jabatan.

Migrasi data ini tidak menyediakan rollback otomatis karena versi global baru tidak bisa dikembalikan ke bulan fiktif. Untuk rollback rilis, pulihkan cadangan database beserta kode sebelumnya. Jangan menjalankan `migrate:fresh` di server produksi.

## Verifikasi

Pengujian mencakup publikasi tanpa data pasangan, dashboard dan ekspor tanpa periode, isolasi draft/publik, konflik preview, alur Livewire admin, kontrol izin, dan migrasi data lama. Pengujian database menggunakan SQLite terisolasi; validasi migrasi pada mesin database produksi tetap dilakukan pada salinan staging sebelum penerapan.


## Status aktif dan arsip

Periode DUK terpublikasi terbaru berlabel **Aktif di dashboard**. Periode sebelumnya berlabel **Arsip** dan tetap tersedia pada filter Daftar Pegawai serta ekspor. Label ini tidak menghapus jejak publikasi historis pada database. Tombol publikasi dinonaktifkan untuk periode yang sudah dipublikasikan dan periode sebelum periode aktif; layanan backend juga menolak publikasi arsip. Mengulangi permintaan publikasi pada periode aktif tidak mengubah tanggal maupun revisinya.

Ekspor Peta Jabatan menyediakan pilihan **Versi 1, Versi 2, dan seterusnya**, tanggal publikasi, serta label Aktif/Arsip. Nomor versi khusus Peta Jabatan diberikan saat hasil impor berhasil disimpan. Impor DUK, preview, impor gagal, dan penyimpanan ulang batch yang sama tidak menaikkan nomor. Versi yang belum dipublikasikan belum tersedia pada ekspor. Hanya versi yang pernah dipublikasikan dan masih memiliki snapshot yang dapat diekspor. Versi draft tidak muncul dalam daftar ekspor.

Migrasi `2026_10_07_000200_track_position_publications` mencatat tanggal publikasi pada versi impor dan memulihkan informasi dari versi aktif serta snapshot periode lama yang diketahui pernah dipublikasikan. Data yang telah dihapus oleh sistem sebelum penyimpanan histori versi diterapkan tidak dapat diciptakan kembali oleh migrasi ini. Jalankan migrasi ini juga saat memperbarui server produksi.

Migrasi `2026_10_07_000300_number_position_versions` memberi nomor versi berurutan pada impor Peta Jabatan tersimpan berdasarkan urutan waktu simpan, tanpa mengganti ID atau relasi data lama. Status versi aktif diseragamkan menjadi **Aktif di dashboard**.
