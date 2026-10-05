# Pengelolaan Template Import

Menu: **Pengelolaan Data > Template Import**.

## Penggunaan

1. Pilih DUK Pegawai atau Peta Jabatan / Kebutuhan.
2. Sesuaikan judul, keterangan, nama tampilan, urutan, warna, lebar, perataan, dan teks tebal kolom.
3. Untuk kolom baru, pilih data standar, data tambahan, atau data tahunan (Peta Jabatan).
4. Periksa preview dan ringkasan perubahan. Pilih **Periksa template**, kemudian **Simpan draft**.
5. Unggah file yang kolomnya sesuai versi template melalui **Uji file contoh**. File yang lolos memuat judul, susunan, warna, lebar, dan format header ke editor draft, serta maksimal 5 baris contoh ke preview. Sesuaikan lalu simpan draft, atau pilih **Batal** untuk kembali ke draft sebelum unggahan. File yang gagal tidak mengubah editor. Isi contoh tidak disimpan sebagai data pegawai.
6. Aktifkan versi tersimpan setelah hasilnya sesuai. Unduhan pada menu import otomatis memakai versi aktif.

Template awal dicatat otomatis sebagai versi 1 yang aktif. Versi aktif yang sudah ada tetap dipertahankan. Draft baru dari halaman template memakai warna kolom putih; membuka draft tersimpan mempertahankan warna yang sudah dipilih. Template terkelola memakai judul dokumen, keterangan, lalu satu baris header terpisah untuk setiap kolom. Nama lembar tetap DUK PEGAWAI atau PETA JABATAN.

## Aturan kolom

- Nama dan posisi kolom dapat berubah; identitas kegunaan kolom tetap.
- Kolom wajib tidak dapat dihapus. Opsi wajib diisi berarti setiap baris harus mempunyai nilai. Aturan data standar tetap berlaku, termasuk pengecualian NIP untuk PPNPN.
- Data tambahan mendukung teks, angka, tanggal YYYY-MM-DD, dan pilihan (satu pilihan per baris). Data ini tersedia pada tabel admin dan ekspor, tidak dipakai otomatis dalam grafik publik.
- Tahun pensiun/proyeksi disimpan berdasarkan jenis dan tahun, bukan posisi kolom.
- Kegunaan dan nama kolom harus unik. Jangan mengubah arti kolom melalui judulnya.
- Seluruh kolom versi yang dipilih harus ada dalam file, walaupun nilainya boleh kosong untuk kolom opsional. Untuk menghapus kolom, buat versi baru.

## Import dan pemetaan

File unduhan membawa penanda versi. Versi lama yang pernah diaktifkan tetap dapat diimpor. Draft hanya dapat diuji di halaman template dan belum dapat dipakai untuk menyimpan data.

Jika judul di Excel berbeda, pilih versi secara manual di Periode dan Import Data, buka pemetaan, lalu isi judul Excel untuk kegunaan yang bersangkutan. Untuk kolom yang tidak digunakan, isi daftar judul yang sengaja diabaikan. Kolom tidak dikenal pada template terkelola menghentikan pemeriksaan. Preview menunjukkan versi, pemetaan, kolom yang diabaikan, dan nilai data tambahan.

Setelah versi kustom diaktifkan, file tanpa penanda meminta pilihan versi secara eksplisit. Versi awal tetap menerima file lama tanpa penanda. Pilihan **Format bawaan lama** tetap menggunakan parser dokumen lama; bukan jalur untuk kolom tambahan. Gunakan versi template untuk perubahan struktur baru.

Riwayat import menyimpan versi dan pemetaan yang digunakan. Mengaktifkan versi lain tidak mengubah data historis maupun versi yang sudah pernah diaktifkan. Untuk menyunting versi tersebut, simpan sebagai draft baru. Aktivasi versi arsip mengembalikan format unduhan sebelumnya.

## Instalasi pembaruan

Jalankan `php artisan migrate` dan `php artisan view:clear`. Migrasi menambahkan tabel versi, JSON data tambahan, dan permission template.view / template.manage. Permission pengelolaan diberikan ke Super Admin dan Admin SDM; Internal Viewer hanya melihat dan mengunduh versi yang sudah pernah diaktifkan. Izin role lain tetap dipertahankan.

Ekspor data memiliki judul dan keterangan pada dua baris pertama. Nama unduhan mengikuti `judul dokumen_keterangan`; keterangan `Bulan - tahun` diganti periode data. Judul memakai versi template dari import data tersebut jika tersedia.

### Uji struktur file sumber

Pilih file, tunggu unggahan selesai, kemudian klik **Uji file contoh**. Pemeriksaan tidak berjalan otomatis. Uji ini hanya memerlukan kolom wajib; kolom opsional yang tidak ada tidak dimasukkan ke draft. Nama sheet Peta Jabatan boleh berbeda selama tepat satu sheet memiliki header wajib yang sesuai. DUK memakai sheet DUK PEGAWAI. Kolom yang belum dikenal tetap ditolak agar tidak salah dipetakan. Validasi isi setiap baris tetap dilakukan saat import data, bukan saat uji struktur template.
