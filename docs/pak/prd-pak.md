# Product Requirements Document (PRD)
## Sistem PAK Konversi BBPMP Provinsi Jawa Timur

**Versi dokumen:** 1.0  
**Tanggal:** 17 Oktober 2026  
**Status:** Draft siap review / acuan implementasi Phase 01  
**Produk induk:** Sistem SDM BBPMP Provinsi Jawa Timur  
**Stack existing:** Laravel 12.x, Filament 3.3, PHP 8.2+, Spatie Permission, MySQL-compatible database  
**Referensi UX/proses:** e-Kinerja BKN (sebagai referensi, bukan aplikasi yang dikloning)

---

## 1. Ringkasan Eksekutif

Modul **PAK Konversi BBPMP Provinsi Jawa Timur** adalah pengembangan dari aplikasi SDM BBPMP Jatim yang sudah berjalan. Modul ini mendigitalisasi proses penghitungan dan pengelolaan Penetapan Angka Kredit (PAK) yang saat ini masih banyak dilakukan secara manual.

Modul PAK tetap berada dalam **satu codebase dan satu database** dengan aplikasi SDM existing, namun menggunakan **Filament Panel terpisah** karena administrator/pengelola proses PAK berbeda dengan administrator SDM umum.

Rangkaian proses utama yang didukung:

1. pengelolaan hasil SKP sebagai sumber Predikat Kinerja;
2. pengelolaan AK Integrasi bagi pegawai yang masih memiliki saldo transisi;
3. penghitungan **Konversi Predikat Kinerja ke Angka Kredit**;
4. **Akumulasi Angka Kredit**;
5. **Penetapan Angka Kredit (PAK Konversi)**;
6. evaluasi kelayakan **Kenaikan Pangkat (KP)** dan/atau **Kenaikan Jenjang (KJ)**;
7. proses lanjutan **Uji Kompetensi** untuk KJ;
8. pencatatan SK dan TMT untuk perubahan pangkat/jenjang;
9. histori/saldo Angka Kredit;
10. pengajuan pemeriksaan KP/KJ oleh pegawai JF;
11. peringatan/warning kepada Tim SDM untuk pegawai yang berpotensi memenuhi persyaratan;
12. pencetakan tiga dokumen PAK sesuai template BBPMP Jatim.

Modul ini tidak bertujuan menyalin e-Kinerja BKN. Pola proses dan UX e-Kinerja dapat dijadikan referensi, tetapi **business rule BBPMP Jatim, regulasi resmi, dokumen nyata, dan keputusan client merupakan acuan implementasi**.

---

## 2. Latar Belakang

BBPMP Provinsi Jawa Timur telah memiliki Sistem SDM yang mengelola antara lain:

- data DUK/pegawai;
- periode pelaporan;
- Peta Jabatan dan kebutuhan;
- dashboard monitoring SDM;
- import/export data;
- role dan permission.

Proses PAK Konversi masih membutuhkan pengelolaan data, perhitungan, verifikasi, penyusunan dokumen, dan histori yang lebih terstruktur.

Materi teori dan contoh fisik menunjukkan tiga tahap utama:

```text
Konversi Predikat Kinerja
        ↓
Akumulasi Angka Kredit
        ↓
Penetapan Angka Kredit / PAK Konversi
```

Hasil PAK kemudian dapat digunakan untuk:

- rekomendasi KP;
- rekomendasi KJ;
- rekomendasi KP dan KJ sekaligus;
- atau menyatakan bahwa persyaratan belum terpenuhi.

Untuk KJ, proses PAK bukan akhir dari proses administrasi. Pegawai yang memenuhi persyaratan KJ tetap harus mengikuti Uji Kompetensi.

---

## 3. Permasalahan yang Diselesaikan

Sistem harus membantu menyelesaikan masalah berikut:

1. Data sumber PAK tersebar pada data pegawai, SKP, AK Integrasi, Peta Jabatan, dan dokumen fisik.
2. Penghitungan Konversi Predikat Kinerja masih berpotensi dilakukan berulang secara manual.
3. Periode SKP yang digunakan untuk PAK tidak selalu tepat satu triwulan atau satu tahun; dapat menggunakan rentang bulan tertentu.
4. Dokumen SKP resmi untuk periode yang dibutuhkan dapat belum tersedia, sementara PAK tetap harus diproses.
5. Sebagian pegawai masih memiliki AK Integrasi sebagai dampak perubahan aturan sebelum 2023.
6. Pengangkatan/perpindahan ke JF tertentu membutuhkan aturan/tabel khusus.
7. KP dan KJ memiliki aturan dan alur lanjutan yang berbeda.
8. KJ membutuhkan Uji Kompetensi.
9. Jika KP dan KJ terpenuhi bersamaan, KJ diproses terlebih dahulu.
10. Histori AK tidak boleh hilang ketika terjadi perubahan jenjang.
11. Pegawai membutuhkan akses untuk melihat data PAK miliknya sendiri.
12. Tim SDM membutuhkan reminder dari pengajuan pegawai dan warning sistem.
13. Tiga dokumen PAK harus dapat dibuat konsisten dari sumber data yang sama.
14. Sistem existing harus tetap stabil dan tidak rusak karena penambahan domain PAK.

---

## 4. Tujuan Produk

### 4.1 Tujuan Utama

1. Mendigitalisasi proses PAK Konversi BBPMP Jatim.
2. Mengurangi penghitungan manual dan input data berulang.
3. Menjaga konsistensi data antara Konversi, Akumulasi, dan Penetapan PAK.
4. Menyediakan histori perhitungan yang dapat diaudit.
5. Menyediakan deteksi awal pegawai yang berpotensi memenuhi KP/KJ.
6. Memberikan portal personal kepada pegawai untuk memantau data PAK miliknya.
7. Menjaga pemisahan kewenangan antara pegawai dan Tim SDM.
8. Memanfaatkan data pegawai dan Peta Jabatan yang sudah ada pada Sistem SDM.
9. Menyediakan fondasi yang nantinya dapat dihubungkan dengan proses KGB setelah PAK stabil.

### 4.2 Sasaran Kualitas

Sistem harus:

- dapat direkonstruksi: hasil perhitungan historis dapat dijelaskan dari sumber/rule yang digunakan;
- tidak melakukan double counting periode AK;
- menyimpan histori perubahan;
- tidak menghapus data PAK final hanya karena data pegawai pada DUK berubah;
- memisahkan perhitungan dari UI Filament;
- mudah menyesuaikan perubahan aturan melalui master rule/versioning;
- dapat diuji otomatis melalui test case numerik.

---

## 5. Scope V1

### 5.1 In Scope

#### A. Integrasi dengan SDM Existing

- menggunakan `people` sebagai master identity;
- menggunakan data DUK existing sebagai referensi kondisi pegawai;
- menggunakan Peta Jabatan/kebutuhan existing sebagai salah satu sumber evaluasi kebutuhan KJ;
- menggunakan user/role/permission existing dan memperluasnya untuk PAK;
- menambahkan hubungan akun user dengan person/pegawai.

#### B. Portal Pegawai

Pegawai yang login dapat:

- melihat profil pribadi;
- melihat riwayat SKP pribadi;
- melihat AK Integrasi jika ada;
- melihat riwayat Konversi Predikat Kinerja;
- melihat Akumulasi AK;
- melihat hasil PAK;
- melihat status KP/KJ miliknya;
- melihat Peta Jabatan/kebutuhan;
- mencetak dokumen PAK yang sudah tersedia;
- mengajukan pemeriksaan KP/KJ jika berada pada Jabatan Fungsional.

#### C. Pengajuan Pegawai JF

Form pengajuan minimal berisi:

- nama (otomatis dari akun);
- NIP (otomatis dari akun);
- pesan;
- waktu pengajuan.

Pengajuan adalah **permintaan pemeriksaan/reminder**, bukan keputusan bahwa pegawai pasti memenuhi KP/KJ.

Pelaksana tidak menggunakan pengajuan manual untuk alur ini; sistem dapat memberikan warning otomatis kepada Tim SDM.

#### D. Panel PAK Tim SDM

Tim Kepegawaian/SDM memiliki akses untuk:

- melihat dashboard PAK;
- melihat pengajuan pegawai;
- melihat warning sistem;
- mengelola SKP;
- mengelola AK Integrasi;
- mengelola proses pengangkatan/perpindahan yang relevan;
- membuat dan memproses PAK;
- menghitung Konversi;
- melakukan Akumulasi;
- melakukan Penetapan PAK;
- melakukan evaluasi KP/KJ;
- mencatat Uji Kompetensi;
- mencatat SK/TMT;
- mencetak dokumen;
- melihat histori dan audit trail;
- mengelola master rule sesuai permission.

#### E. SKP

Sistem mendukung:

- Triwulan I;
- Triwulan II;
- Triwulan III;
- Triwulan IV;
- Evaluasi Akhir;
- input predikat provisional/darurat oleh SDM jika dokumen resmi belum tersedia;
- pemilihan/perhitungan berdasarkan rentang bulan yang diperlukan PAK.

#### F. Konversi Predikat Kinerja

Sistem mendukung:

- perhitungan tahunan;
- perhitungan proporsional berdasarkan rentang bulan;
- penggunaan predikat dan koefisien yang sesuai;
- pemecahan satu rentang PAK menjadi beberapa segment periode bila sumber predikat berbeda;
- deteksi overlap periode.

#### G. AK Integrasi

Sistem dapat:

- menyimpan nilai AK Integrasi;
- menyimpan tanggal efektif/dasar dokumen;
- menggunakan AK Integrasi sebagai salah satu komponen/saldo awal sesuai kasus;
- mempertahankan histori.

#### H. Pengangkatan/Perpindahan ke JF

Sistem harus menyediakan dukungan rule khusus untuk kasus yang menurut regulasi/client menggunakan:

- AK Dasar;
- Konversi Predikat Kinerja;
- batas masa kinerja tertentu;
- nilai tetap dari tabel perpindahan;
- carry/pass value AK JF sebelumnya bila relevan.

Implementasi akhir mengikuti `business-rules.md`.

#### I. Akumulasi Angka Kredit

Sistem mengakumulasi komponen AK yang sah untuk satu PAK dan menyimpan snapshot hasil final agar dokumen historis tidak berubah.

#### J. Penetapan Angka Kredit

Sistem membandingkan AK kumulatif terhadap target KP/KJ dan menghasilkan hasil evaluasi:

- belum memenuhi;
- memenuhi AK KP;
- memenuhi AK KJ;
- memenuhi AK KP dan KJ.

Status memenuhi AK bukan berarti kenaikan administratif otomatis.

#### K. KP

PAK menjadi tahap akhir perhitungan/rekomendasi PAK untuk KP.

Kenaikan pangkat final tetap mengikuti proses administrasi dan dokumen yang berlaku.

#### L. KJ

Jika memenuhi persyaratan KJ:

- sistem mencatat status rekomendasi;
- pegawai mengikuti Uji Kompetensi;
- hasil Ukom dicatat;
- jika lulus, proses KJ dapat dilanjutkan;
- jika tidak lulus, pegawai tetap pada jenjang existing.

#### M. KP + KJ Bersamaan

Jika keduanya terpenuhi:

- KJ diproses terlebih dahulu;
- setelah KJ selesai sesuai rule, KP diproses;
- AK yang sama tidak boleh ter-reset terlalu dini.

#### N. Saldo/Histori AK

Saat KJ efektif:

- saldo siklus baru dapat dimulai sesuai rule;
- nilai/sisa lama tetap berada dalam history;
- tidak ada hard delete histori AK.

#### O. Dokumen

Sistem menghasilkan:

1. Konversi Predikat Kinerja ke Angka Kredit;
2. Akumulasi Angka Kredit;
3. Penetapan Angka Kredit.

V1:

- nomor dokumen dikosongkan;
- tidak ada nomor otomatis;
- tidak ada TTE;
- dokumen dicetak;
- tanda tangan dilakukan secara basah/manual;
- template dapat dikelola/versioning oleh admin sesuai kewenangan.

#### P. Audit Trail

Sistem harus mencatat aktivitas penting seperti:

- input/edit SKP;
- input SKP provisional;
- perhitungan PAK;
- finalisasi;
- perubahan status;
- hasil Ukom;
- input SK/TMT;
- generate dokumen;
- perubahan rule/master sensitif.

---

## 6. Out of Scope V1

Tidak termasuk pada V1:

- Kenaikan Gaji Berkala (KGB);
- payroll;
- presensi;
- TTE/tanda tangan digital;
- penomoran dokumen PAK otomatis;
- sinkronisasi otomatis ke SIASN/MyASN/BKN;
- perubahan data PAK final secara langsung tanpa mekanisme koreksi/versioning;
- workflow penuh surat-menyurat eksternal;
- mobile native application;
- clone 1:1 UI e-Kinerja BKN.

KGB akan dianalisis setelah PAK stabil.

---

## 7. Pengguna dan Hak Akses Konseptual

### 7.1 Pegawai

Dapat:

- melihat data miliknya sendiri;
- melihat Peta Jabatan;
- melihat SKP/AK/PAK miliknya;
- mencetak dokumen miliknya;
- melakukan pengajuan pemeriksaan KP/KJ jika memenuhi jenis jabatan yang diizinkan.

Tidak dapat:

- mengubah AK;
- mengubah hasil SKP final;
- mengubah rule;
- menetapkan KP/KJ;
- melihat data pribadi pegawai lain.

### 7.2 Tim Kepegawaian / SDM / Admin PAK

Dapat mengelola keseluruhan proses PAK sesuai permission.

### 7.3 Super Admin

Dapat:

- mengelola user;
- role;
- permission;
- konfigurasi/master rule;
- akses administratif lintas panel sesuai kebijakan.

### 7.4 Pimpinan

Pada V1 tidak wajib login untuk melakukan tanda tangan digital.

Dokumen dicetak dan ditandatangani manual.

Jika di kemudian hari diperlukan approval/login pimpinan, ditambahkan pada fase berikutnya.

---

## 8. Information Architecture

### 8.1 Existing

```text
/                → Public Dashboard SDM
/admin           → Filament Admin SDM existing
```

### 8.2 PAK

Direkomendasikan:

```text
/pak             → Filament Panel PAK / Tim SDM
/pegawai         → Portal/Panel Pegawai
```

Semua tetap berada dalam:

- satu Laravel application;
- satu database;
- master pegawai yang sama.

---

## 9. Modul PAK

### 9.1 Dashboard PAK

Menampilkan minimal:

- pengajuan baru;
- kasus PAK aktif;
- pegawai berpotensi memenuhi KP;
- pegawai berpotensi memenuhi KJ;
- pegawai berpotensi memenuhi KP+KJ;
- KJ menunggu Ukom;
- menunggu SK;
- menunggu TMT;
- SKP provisional yang belum digantikan data resmi;
- PAK yang membutuhkan perhatian.

### 9.2 Pengajuan Pegawai

Fungsi:

- pegawai JF mengirim permintaan pemeriksaan;
- masuk ke inbox Tim SDM;
- dapat dikaitkan dengan PAK Case;
- memiliki status review.

### 9.3 SKP

Fungsi:

- menyimpan evaluasi resmi;
- menyimpan provisional;
- menyimpan periode;
- menyimpan predikat;
- mengaitkan dokumen;
- digunakan sebagai sumber Konversi.

### 9.4 AK Integrasi

Fungsi:

- menyimpan saldo/nilai awal transisi;
- menyimpan sumber;
- dipakai sesuai kondisi.

### 9.5 PAK Case

Satu PAK Case merepresentasikan satu proses PAK seorang pegawai untuk suatu periode/kebutuhan.

### 9.6 Konversi

Menghasilkan satu atau lebih detail AK per periode.

### 9.7 Akumulasi

Menggabungkan seluruh komponen AK yang sah.

### 9.8 Penetapan

Menghasilkan hasil PAK final dan rekomendasi KP/KJ.

### 9.9 Uji Kompetensi

Mencatat proses lanjutan KJ.

### 9.10 SK/TMT

Mencatat hasil keputusan administrasi dan tanggal efektif.

### 9.11 Dokumen

Membuat tiga dokumen PAK.

### 9.12 History/Audit

Memberikan keterlacakan dari data sumber sampai hasil final.

---

## 10. Terminologi Wajib

Istilah berikut tidak boleh disamakan di kode/database:

| Istilah | Arti |
|---|---|
| Pangkat | Nama pangkat, contoh Penata Tk.I |
| Golongan Ruang | Kode seperti III/d |
| Jabatan | Nama jabatan, contoh Arsiparis |
| Kategori JF | Keahlian / Keterampilan |
| Jenjang JF | Ahli Pertama/Muda/Madya/Utama atau Pemula/Terampil/Mahir/Penyelia |
| KP | Kenaikan Pangkat |
| KJ | Kenaikan Jenjang |
| SKP | Sasaran Kinerja Pegawai / sumber penilaian kinerja |
| AK | Angka Kredit |
| PAK | Penetapan Angka Kredit |
| AK Integrasi | AK transisi yang dimiliki pegawai tertentu |
| SK | Surat Keputusan |
| TMT | Terhitung Mulai Tanggal |

---

## 11. Prinsip Produk

1. **Human-in-the-loop.** Sistem membantu menghitung dan mendeteksi, tetapi keputusan administrasi tetap melalui pihak berwenang.
2. **Auditability.** Setiap hasil penting harus dapat ditelusuri.
3. **No destructive history.** Riwayat tidak dihapus karena perubahan posisi terbaru.
4. **Versioned rules.** Rule yang berubah tidak boleh merusak hasil PAK lama.
5. **Separation of concern.** Perhitungan berada di Domain/Service layer.
6. **Employee privacy.** Pegawai hanya melihat data person-level miliknya.
7. **Existing SDM stability.** Fitur lama harus tetap backward compatible.

---

## 12. Referensi dan Prioritas Acuan

Prioritas ketika terdapat perbedaan informasi:

1. regulasi resmi yang berlaku;
2. panduan teknis resmi BKN;
3. keputusan bisnis client BBPMP Jatim yang tidak bertentangan dengan regulasi;
4. dokumen PAK nyata BBPMP Jatim;
5. materi PPT BKN/Kemendikdasmen;
6. e-Kinerja BKN sebagai referensi proses/UX;
7. video tutorial;
8. asumsi developer.

Asumsi developer tidak boleh langsung menjadi business rule.

---

## 13. Non-Functional Requirements

### 13.1 Security

- semua halaman PAK internal wajib autentikasi;
- permission diterapkan per aksi;
- pegawai hanya melihat data sendiri;
- file dokumen disimpan private;
- data sensitif tidak dicatat penuh di application log;
- perubahan rule penting diaudit.

### 13.2 Reliability

- perhitungan final bersifat reproducible;
- finalisasi PAK menggunakan transaction;
- gagal generate/finalize tidak merusak data final sebelumnya;
- database backup tetap mengikuti kebijakan aplikasi SDM.

### 13.3 Maintainability

- Laravel migration sebagai schema source of truth;
- tidak membuat tabel secara manual di produksi;
- nilai koefisien/target tidak di-hard-code;
- business logic tidak diletakkan di Filament Resource/Page;
- enum/status terpusat;
- setiap rule numerik penting memiliki automated test.

### 13.4 Precision

- perhitungan AK menggunakan decimal yang terkontrol;
- hindari floating-point yang menghasilkan error;
- kebijakan rounding harus eksplisit dan diuji.

### 13.5 Performance

Dataset awal relatif kecil. Target:

- halaman daftar umum < 2 detik;
- perhitungan satu PAK < 1 detik pada kondisi normal;
- query dashboard tidak melakukan N+1 besar;
- generate dokumen per kasus tetap responsif.

---

## 14. Acceptance Criteria V1

### Foundation

- [ ] Existing `/admin` tetap berfungsi.
- [ ] Panel `/pak` tersedia dan dibatasi permission.
- [ ] Akun pegawai dapat dipetakan ke `people`.
- [ ] Data historis PAK tidak bergantung pada primary key snapshot DUK.
- [ ] Rule master memiliki masa berlaku/versioning.

### Pegawai

- [ ] Pegawai hanya dapat melihat data dirinya.
- [ ] Pegawai JF dapat mengajukan pemeriksaan KP/KJ.
- [ ] Nama dan NIP pengajuan berasal dari akun/person, bukan input bebas.
- [ ] Pegawai dapat melihat riwayat PAK miliknya.
- [ ] Pegawai dapat mencetak dokumen yang sudah tersedia.

### SKP

- [ ] Sistem dapat menyimpan 4 Triwulan + Evaluasi Akhir.
- [ ] Sistem dapat menggunakan rentang bulan tertentu.
- [ ] Sistem dapat menerima predikat provisional/darurat oleh SDM.
- [ ] Sistem mencegah penggunaan periode AK yang overlap/double count dalam satu perhitungan final.

### Konversi

- [ ] Sistem menghitung tahunan.
- [ ] Sistem menghitung proporsional.
- [ ] Koefisien berasal dari master rule.
- [ ] Predikat/persentase berasal dari master rule.
- [ ] Golden test Nurmaulida menghasilkan nilai yang sesuai.

### Akumulasi

- [ ] Sistem menggabungkan komponen AK secara konsisten.
- [ ] AK Integrasi dapat ikut diperhitungkan sesuai rule.
- [ ] Akumulasi final menyimpan snapshot agar histori stabil.

### PAK

- [ ] Sistem dapat menilai target KP.
- [ ] Sistem dapat menilai target KJ.
- [ ] Sistem dapat menilai KP+KJ.
- [ ] Memenuhi AK tidak otomatis dianggap kenaikan administratif final.

### KJ

- [ ] KJ yang memenuhi AK masuk tahap Ukom.
- [ ] Hasil Ukom lulus/gagal dapat dicatat.
- [ ] Gagal Ukom tidak mengubah jenjang aktif.

### KP+KJ

- [ ] Jika keduanya memenuhi, sistem mengarahkan KJ terlebih dahulu.
- [ ] Saldo AK tidak di-reset sebelum rangkaian yang membutuhkan AK tersebut selesai.

### SK/TMT

- [ ] SK dapat dicatat.
- [ ] TMT dapat dicatat.
- [ ] Histori pangkat/jabatan dapat diperbarui berdasarkan event yang sah.
- [ ] Tanggal efektif saldo baru mengikuti rule yang telah disetujui.

### Dokumen

- [ ] Tiga jenis dokumen dapat dihasilkan.
- [ ] Nomor dokumen boleh kosong.
- [ ] Tidak ada kewajiban TTE V1.
- [ ] Template memiliki versi.
- [ ] Dokumen final historis tidak ikut berubah ketika template baru dibuat.

### Audit

- [ ] Aktivitas penting memiliki user + timestamp.
- [ ] Input provisional dapat ditelusuri.
- [ ] Finalisasi PAK dapat ditelusuri.
- [ ] Perubahan rule dapat ditelusuri.

---

## 15. Risiko Utama

1. Interpretasi periode tiga tahun belum seluruhnya dikunci menjadi definisi teknis universal.
2. Rule beberapa skenario pengangkatan/perpindahan perlu divalidasi terhadap kasus nyata.
3. Perlakuan PAK setelah gagal Ukom perlu konfirmasi lanjutan.
4. Tanggal reset/saldo baru setelah KJ perlu keputusan final apakah menggunakan SK atau TMT.
5. Data historis pegawai existing mungkin belum lengkap.
6. Mapping nama jabatan existing ke master JF dapat membutuhkan normalisasi manual.

Risiko yang belum diputuskan harus ditandai sebagai `PENDING`, bukan diasumsikan.

---

## 16. Fase Implementasi

### Phase 01 — Foundation

- dokumentasi;
- user ↔ person;
- employee profile/history;
- panel PAK;
- permission;
- master JF;
- master rule;
- PAK Case;
- test infrastructure.

### Phase 02 — SKP & Conversion

- performance evaluation;
- official/provisional SKP;
- period segmentation;
- Conversion Engine;
- golden tests.

### Phase 03 — Accumulation & Determination

- AK Integrasi;
- credit entries/ledger;
- Akumulasi;
- PAK Penetapan;
- target KP/KJ.

### Phase 04 — KP/KJ Workflow

- eligibility administratif;
- Ukom;
- KJ;
- KP setelah KJ;
- SK/TMT;
- AK balance lifecycle.

### Phase 05 — Portal & Documents

- employee portal;
- pengajuan;
- warning;
- printable documents;
- template versioning;
- UAT.

---

## 17. Definition of Done V1

PAK V1 dianggap selesai ketika:

1. kasus PAK dapat dibuat untuk pegawai existing;
2. SKP/AK Integrasi dapat dikelola;
3. Konversi dapat dihitung berdasarkan periode fleksibel;
4. Akumulasi dapat dibuat;
5. Penetapan PAK dapat menentukan hasil KP/KJ;
6. KJ dapat melanjutkan ke Ukom;
7. SK/TMT dan histori dapat dicatat;
8. pegawai dapat melihat data pribadi dan mengajukan pemeriksaan;
9. tiga dokumen dapat dicetak;
10. seluruh golden/critical test lulus;
11. existing SDM tetap berjalan tanpa regression kritis.
