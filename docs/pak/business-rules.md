# Business Rules
## Sistem PAK Konversi BBPMP Provinsi Jawa Timur

**Versi:** 1.0  
**Tanggal:** 17 Oktober 2026  
**Status:** Draft untuk review implementasi

---

## 1. Tujuan

Dokumen ini adalah source of truth untuk aturan bisnis PAK yang digunakan oleh calculation engine dan workflow.

Setiap rule memiliki ID stabil. Perubahan implementasi harus merujuk ID rule terkait.

Status rule:

- `APPROVED` — telah dikonfirmasi/didukung sumber yang digunakan;
- `SYSTEM` — aturan teknis untuk menjaga integritas sistem;
- `PENDING` — belum boleh di-hard-code sebagai keputusan final;
- `REFERENCE` — aturan dari materi/referensi yang perlu dipakai bersama regulasi.

---

# A. Terminologi

## BR-TERM-001 — Pangkat dan Golongan Ruang
**Status:** APPROVED

`Pangkat` dan `Golongan Ruang` adalah data berbeda.

Contoh:

```text
Pangkat        = Penata Tk.I
Golongan Ruang = III/d
```

Tidak boleh direpresentasikan sebagai satu field konseptual.

## BR-TERM-002 — Jabatan dan Jenjang
**Status:** APPROVED

`Jabatan` dan `Jenjang JF` adalah data berbeda.

Contoh:

```text
Jabatan    = Arsiparis
Jenjang JF = Penyelia
```

## BR-TERM-003 — KP
**Status:** APPROVED

KP = Kenaikan Pangkat.

## BR-TERM-004 — KJ
**Status:** APPROVED

KJ = Kenaikan Jenjang Jabatan Fungsional.

---

# B. Aktor dan Kewenangan

## BR-ACTOR-001 — Pengelola PAK
**Status:** APPROVED

Seluruh pengisian:

1. Konversi Predikat Kinerja;
2. Akumulasi Angka Kredit;
3. Penetapan Angka Kredit;

dilakukan oleh Tim Kepegawaian/SDM.

Pegawai tidak menginput angka perhitungan PAK sendiri.

## BR-ACTOR-002 — Pengajuan Pegawai JF
**Status:** APPROVED

Pegawai yang berada pada Jabatan Fungsional dapat mengirim permintaan pemeriksaan KP/KJ apabila merasa AK telah mencukupi.

Pengajuan berfungsi sebagai reminder kepada SDM.

Pengajuan bukan bukti bahwa pegawai telah memenuhi KP/KJ.

## BR-ACTOR-003 — Pelaksana
**Status:** APPROVED

Pelaksana tidak menggunakan mekanisme pengajuan pribadi tersebut.

Sistem dapat menghasilkan warning kepada SDM berdasarkan data yang tersedia.

## BR-ACTOR-004 — Warning bukan keputusan
**Status:** SYSTEM

Warning otomatis hanya berarti:

```text
"Layak diperiksa / membutuhkan perhatian"
```

dan bukan:

```text
"Otomatis naik pangkat/jenjang"
```

---

# C. Sumber Kinerja / SKP

## BR-SKP-001 — Jenis Evaluasi
**Status:** APPROVED

Dalam satu tahun pegawai dapat mempunyai:

- Triwulan I;
- Triwulan II;
- Triwulan III;
- Triwulan IV;
- Evaluasi Akhir.

## BR-SKP-002 — Evaluasi Akhir sebagai sumber terakhir
**Status:** APPROVED

Jika Evaluasi Akhir resmi telah tersedia dan digunakan untuk satu tahun penuh, data predikat Evaluasi Akhir menjadi sumber utama untuk periode tahunan tersebut.

## BR-SKP-003 — Periode PAK fleksibel
**Status:** APPROVED

Periode yang digunakan untuk Konversi PAK tidak harus tepat batas triwulan atau tahun.

Contoh valid:

```text
1 Januari 2025 - 31 Mei 2025
1 Januari 2026 - 31 Oktober 2026
```

## BR-SKP-004 — Segmentasi periode
**Status:** SYSTEM

Jika satu rentang PAK mencakup beberapa sumber predikat, rentang harus dipecah menjadi segment non-overlap.

Contoh:

```text
Jan-Mar  -> sumber TW I
Apr-May  -> sumber TW II
```

## BR-SKP-005 — Tidak boleh double counting
**Status:** SYSTEM

Bulan/periode yang sama tidak boleh dihitung dua kali ke dalam AK final pada PAK Case yang sama.

Jika periode Jan-Jun telah digunakan dari evaluasi periodik, sistem tidak boleh menjumlahkan kembali Jan-Jun dari Evaluasi Akhir untuk case yang sama.

## BR-SKP-006 — Input darurat/provisional
**Status:** APPROVED

Apabila dokumen SKP resmi untuk bulan yang dibutuhkan belum terbit, Tim SDM dapat memasukkan predikat provisional/darurat.

Minimal wajib menyimpan:

- person;
- periode;
- predikat;
- alasan;
- penginput;
- waktu input;
- status `PROVISIONAL`.

## BR-SKP-007 — Data provisional tidak dihapus
**Status:** SYSTEM

Ketika data resmi tersedia, record provisional tidak boleh di-hard-delete.

Record dapat:

- ditandai `SUPERSEDED`;
- ditautkan ke data resmi pengganti;
- atau dikoreksi melalui mekanisme versioning.

## BR-SKP-008 — Perbedaan provisional vs resmi
**Status:** PENDING

Jika predikat resmi yang terbit berbeda dari provisional dan PAK telah difinalisasi, prosedur koreksi administrasi harus dikonfirmasi client.

Sistem V1 harus menyimpan bukti keduanya dan tidak overwrite diam-diam.

---

# D. Periode PAK

## BR-PERIOD-001 — Tiga tahun terakhir
**Status:** APPROVED secara bisnis, definisi teknis sebagian PENDING

Client menjelaskan sumber SKP PAK menggunakan tiga tahun terakhir dan contoh dokumen nyata dapat memiliki rentang seperti:

```text
1 November 2023 - 31 Oktober 2026
```

Sistem harus mendukung rentang tanggal eksplisit.

## BR-PERIOD-002 — Tidak hard-code tahun kalender
**Status:** SYSTEM

Jangan mengimplementasikan periode hanya sebagai:

```text
2023, 2024, 2025
```

Gunakan:

```text
period_start
period_end
```

## BR-PERIOD-003 — Definisi rolling 36 bulan
**Status:** PENDING

Usulan teknis: interpretasi tiga tahun sebagai rolling 36 bulan.

Harus dikonfirmasi sebelum dijadikan validasi blocking universal.

Sistem tetap harus dapat memproses explicit date range agar fleksibel.

---

# E. Predikat Kinerja

## BR-PRED-001 — Persentase Predikat
**Status:** REFERENCE

Master awal:

| Predikat | Persentase |
|---|---:|
| Sangat Baik | 150% |
| Baik | 100% |
| Cukup/Butuh Perbaikan | 75% |
| Kurang | 50% |
| Sangat Kurang | 25% |

Nilai tidak boleh di-hard-code ke calculation service.

## BR-PRED-002 — Versioning
**Status:** SYSTEM

Persentase predikat disimpan sebagai master rule yang memiliki masa berlaku.

---

# F. Koefisien Angka Kredit

## BR-KOEF-001 — Master awal
**Status:** REFERENCE

| Jenjang | Koefisien / Tahun |
|---|---:|
| Ahli Utama | 50 |
| Ahli Madya | 37.5 |
| Ahli Muda | 25 |
| Ahli Pertama | 12.5 |
| Penyelia | 25 |
| Mahir | 12.5 |
| Terampil | 5 |
| Pemula | 3.75 |

## BR-KOEF-002 — Tidak hard-code
**Status:** SYSTEM

Calculation Engine membaca koefisien dari rule/version yang berlaku.

## BR-KOEF-003 — Snapshot rule
**Status:** SYSTEM

Saat PAK difinalisasi, nilai koefisien/persentase yang digunakan harus tersimpan pada detail perhitungan.

Perubahan master di masa depan tidak mengubah hasil historis.

---

# G. Rumus Konversi

## BR-KONV-001 — Tahunan
**Status:** REFERENCE

```text
AK = Persentase Predikat × Koefisien AK Tahunan
```

## BR-KONV-002 — Periodik / proporsional
**Status:** REFERENCE

```text
AK = (Jumlah Bulan / 12)
     × Persentase Predikat
     × Koefisien AK Tahunan
```

## BR-KONV-003 — Precision
**Status:** SYSTEM

Perhitungan internal menggunakan decimal presisi.

Dilarang bergantung pada binary floating point tanpa kontrol.

## BR-KONV-004 — Rounding
**Status:** SYSTEM / PENDING detail

Output dokumen saat ini mengikuti pola tiga digit desimal pada kasus seperti:

```text
4.166666... -> 4.167
20.833333... -> 20.833
```

Kebijakan rounding final harus terpusat dan dapat diuji.

## BR-KONV-005 — Satu PAK banyak konversi
**Status:** APPROVED

Satu PAK Case dapat memiliki banyak detail Konversi.

---

# H. AK Integrasi

## BR-INTEGRASI-001 — Pegawai tertentu
**Status:** APPROVED

Pegawai yang memiliki proses/riwayat sebelum perubahan aturan 2023 dapat memiliki AK Integrasi.

## BR-INTEGRASI-002 — Komponen histori
**Status:** SYSTEM

AK Integrasi disimpan sebagai data historis dengan:

- nilai;
- tanggal efektif;
- dokumen sumber bila ada;
- catatan.

## BR-INTEGRASI-003 — Tidak ditimpa
**Status:** SYSTEM

Nilai AK Integrasi historis tidak boleh diubah hanya karena DUK terkini berubah.

---

# I. Pengangkatan / Perpindahan ke JF

## BR-APPOINT-001 — Jenis proses harus eksplisit
**Status:** SYSTEM

Sistem harus membedakan minimal:

- Pengangkatan Pertama;
- Perpindahan dari Jabatan Lain;
- Penyesuaian/Penyetaraan;
- Promosi;
- Perpindahan JF ke JF bila masuk scope.

Jangan menentukan rule hanya dari teks jabatan lama.

## BR-APPOINT-002 — Tabel khusus
**Status:** APPROVED

Kasus perpindahan/pengangkatan tertentu menggunakan tabel khusus.

Rule ditentukan oleh kombinasi seperti:

- asal jabatan;
- pangkat/golongan;
- kesesuaian jenjang;
- jabatan/jenjang tujuan;
- masa kepangkatan;
- AK dasar;
- nilai tetap jika tabel mengatur demikian.

## BR-APPOINT-003 — Jabatan Administrasi sesuai jenjang
**Status:** REFERENCE

Formula umum pada materi:

```text
AK = Konversi Predikat Kinerja + AK Dasar
```

## BR-APPOINT-004 — Pangkat tertinggi > 3 tahun
**Status:** REFERENCE

Formula umum pada materi:

```text
AK = Konversi Predikat Kinerja maksimum 3 tahun + AK Dasar
```

## BR-APPOINT-005 — Pangkat tidak sesuai jenjang
**Status:** REFERENCE

Gunakan nilai tabel AK perpindahan antar kelompok jabatan dengan pangkat tidak sesuai jenjang.

## BR-APPOINT-006 — JF ke JF
**Status:** REFERENCE

Pada kondisi yang berlaku, AK dapat mengikuti AK JF sebelumnya sesuai ketentuan.

## BR-APPOINT-007 — Penggunaan rule khusus
**Status:** APPROVED

Menurut client, tabel khusus terutama digunakan pada proses awal pengangkatan/perpindahan ke JF.

PAK rutin setelah pegawai aktif dalam JF mengikuti rule normal sesuai kondisi jabatan/jenjang yang berlaku.

## BR-APPOINT-008 — Istilah “pengangkatan pertama”
**Status:** PENDING

Perlu konfirmasi terminologi agar tidak mencampur:

- Pengangkatan Pertama;
- Perpindahan dari Jabatan Lain.

Implementasi harus menyimpan `appointment_type` eksplisit.

---

# J. Akumulasi

## BR-AKUM-001 — Tahap kedua
**Status:** APPROVED

Akumulasi dilakukan setelah Konversi.

## BR-AKUM-002 — Komponen
**Status:** SYSTEM

Akumulasi dapat terdiri dari komponen seperti:

- AK Integrasi;
- AK Dasar;
- AK JF lama;
- AK Penyesuaian/Penyetaraan;
- AK Konversi;
- AK peningkatan pendidikan;
- AK perpindahan;
- komponen sah lainnya sesuai rule.

## BR-AKUM-003 — Traceability
**Status:** SYSTEM

Setiap angka pada Akumulasi harus dapat ditelusuri ke source/detail perhitungan.

## BR-AKUM-004 — Snapshot final
**Status:** SYSTEM

Setelah finalisasi, hasil Akumulasi disimpan sebagai snapshot immutable/versioned.

---

# K. Target KP/KJ

## BR-TARGET-001 — Master target
**Status:** REFERENCE

Master awal kebutuhan AK model konversi:

| Kategori | Jenjang | Pangkat | KP | KJ |
|---|---|---|---:|---:|
| Keahlian | Ahli Utama | IV/d–IV/e | 200 | - |
| Keahlian | Ahli Madya | IV/a–IV/c | 150 | 450 |
| Keahlian | Ahli Muda | III/c–III/d | 100 | 200 |
| Keahlian | Ahli Pertama | III/a–III/b | 50 | 100 |
| Keterampilan | Penyelia | III/c–III/d | 100 | - |
| Keterampilan | Mahir | III/a–III/b | 50 | 100 |
| Keterampilan | Terampil | II/b–II/d | 20 | 60 |
| Keterampilan | Pemula | II/a | 15 | 15 |

Master tidak boleh di-hard-code.

## BR-TARGET-002 — AK vs kelayakan administratif
**Status:** SYSTEM

`AK terpenuhi` dan `kenaikan disetujui` adalah status berbeda.

---

# L. Kenaikan Pangkat

## BR-KP-001 — PAK sebagai akhir perhitungan KP
**Status:** APPROVED

Untuk alur PAK, Penetapan PAK menghasilkan rekomendasi apakah kebutuhan AK KP telah terpenuhi.

## BR-KP-002 — Syarat non-AK
**Status:** REFERENCE

Eligibility KP dapat mencakup syarat non-AK sesuai regulasi, termasuk masa pangkat dan predikat kinerja.

Implementasi wajib memisahkan:

```text
AK eligibility
administrative eligibility
final decision
```

## BR-KP-003 — Tidak otomatis
**Status:** SYSTEM

Sistem tidak boleh mengubah pangkat aktif hanya karena `kp_ak_met = true`.

Perubahan aktif harus berasal dari event keputusan/SK/TMT yang sah.

---

# M. Kenaikan Jenjang

## BR-KJ-001 — PAK memenuhi KJ
**Status:** APPROVED

Jika PAK memenuhi kebutuhan KJ, pegawai direkomendasikan ke proses Uji Kompetensi.

## BR-KJ-002 — Uji Kompetensi
**Status:** APPROVED

KJ membutuhkan Uji Kompetensi.

## BR-KJ-003 — Lulus Ukom
**Status:** APPROVED

Jika Ukom lulus, KJ dapat dilanjutkan ke penetapan/administrasi.

## BR-KJ-004 — Gagal Ukom
**Status:** APPROVED

Jika Ukom tidak lulus:

- jenjang aktif tidak berubah;
- histori PAK dan Ukom tetap disimpan.

## BR-KJ-005 — Reuse PAK setelah gagal Ukom
**Status:** PENDING

Belum dipastikan apakah periode berikutnya cukup Ukom ulang atau perlu PAK baru/recalculation.

Jangan hard-code perilaku ini sebelum konfirmasi.

## BR-KJ-006 — Kebutuhan jabatan/formasi
**Status:** REFERENCE

KJ perlu memperhatikan kebutuhan jabatan sesuai regulasi.

Sistem dapat menggunakan data Peta Jabatan yang published sebagai sumber, tetapi snapshot sumber keputusan harus disimpan.

---

# N. KP + KJ Bersamaan

## BR-KPKJ-001 — Prioritas KJ
**Status:** APPROVED

Jika pegawai memenuhi KP dan KJ sekaligus:

```text
KJ diproses lebih dahulu
kemudian KP
```

## BR-KPKJ-002 — Jangan reset terlalu dini
**Status:** SYSTEM

Jika AK yang sama masih diperlukan untuk proses KP setelah KJ, balance tidak boleh di-reset sebelum dependency selesai.

---

# O. Saldo AK dan History

## BR-BAL-001 — KJ reset siklus
**Status:** APPROVED secara konsep

Setelah pegawai mendapatkan KJ, kelebihan/sisa AK tidak diteruskan sebagai saldo aktif jenjang baru sesuai penjelasan client.

Riwayat tetap disimpan.

## BR-BAL-002 — Tidak hard delete
**Status:** SYSTEM

Jangan melakukan destructive update:

```text
total_ak = 0
```

tanpa histori.

Gunakan siklus/snapshot/ledger.

## BR-BAL-003 — SK vs TMT
**Status:** PENDING

Usulan:

- SK = bukti keputusan/legal event;
- TMT = tanggal efektif perubahan jenjang dan siklus AK baru.

Belum boleh dijadikan business rule final sampai client mengonfirmasi.

## BR-BAL-004 — Status menunggu TMT
**Status:** SYSTEM / PROPOSED

Jika SK telah ada tetapi TMT di masa depan, sistem sebaiknya menyimpan status `WAITING_TMT` dan tidak mengaktifkan jenjang baru sebelum tanggal efektif.

---

# P. Dokumen

## BR-DOC-001 — Tiga dokumen
**Status:** APPROVED

Sistem menghasilkan:

1. Konversi Predikat Kinerja ke Angka Kredit;
2. Akumulasi Angka Kredit;
3. Penetapan Angka Kredit.

## BR-DOC-002 — Nomor
**Status:** APPROVED

V1 tidak membuat nomor dokumen otomatis.

Kolom nomor dapat kosong saat generate/print.

## BR-DOC-003 — Tanda tangan
**Status:** APPROVED

V1 tidak menggunakan TTE otomatis.

Dokumen ditandatangani basah/manual oleh pimpinan.

## BR-DOC-004 — Template
**Status:** APPROVED

Template dapat dikelola/diubah oleh admin yang memiliki permission.

## BR-DOC-005 — Versioning template
**Status:** SYSTEM

Perubahan template baru tidak boleh mengubah hasil dokumen historis yang telah difinalisasi.

## BR-DOC-006 — Finalized document
**Status:** SYSTEM

Dokumen final yang sudah diterbitkan/generated harus menyimpan template version dan snapshot data yang digunakan.

---

# Q. PAK Case

## BR-CASE-001 — Unit proses
**Status:** SYSTEM

Satu `PAK Case` merepresentasikan satu proses PAK seorang pegawai untuk periode/kebutuhan tertentu.

## BR-CASE-002 — Identitas
**Status:** SYSTEM

PAK Case harus mengacu ke `people.id`.

Jangan mengacu hanya ke `personnel_snapshots.id`.

## BR-CASE-003 — Snapshot pegawai
**Status:** SYSTEM

PAK menyimpan snapshot data yang digunakan pada saat proses/finalisasi:

- nama;
- NIP;
- pangkat;
- golongan ruang;
- jabatan;
- jenjang;
- unit kerja;
- TMT relevan.

Perubahan DUK setelahnya tidak mengubah PAK lama.

---

# R. Warning dan Pengajuan

## BR-WARN-001 — Sumber proses
**Status:** APPROVED

PAK dapat dimulai karena:

- pengajuan pegawai JF;
- warning sistem;
- inisiasi manual SDM.

## BR-WARN-002 — Pelaksana
**Status:** APPROVED

Pelaksana dapat masuk perhatian SDM melalui warning sistem tanpa pengajuan pribadi.

## BR-WARN-003 — Warning configurable
**Status:** SYSTEM

Rule warning harus dipisahkan dari rule keputusan final agar dapat dituning tanpa mengubah histori PAK.

---

# S. Rule Versioning dan Audit

## BR-AUDIT-001 — Rule validity
**Status:** SYSTEM

Master rule memiliki minimal:

- `valid_from`;
- `valid_until`;
- reference/source;
- version/rule set.

## BR-AUDIT-002 — Final record
**Status:** SYSTEM

Finalized calculation tidak dihitung ulang otomatis hanya karena master rule berubah.

## BR-AUDIT-003 — User trace
**Status:** SYSTEM

Aksi penting menyimpan:

- user;
- timestamp;
- old/new value bila relevan;
- alasan bila diwajibkan.

---

# T. Keputusan yang Masih Pending

| ID | Pertanyaan |
|---|---|
| BR-SKP-008 | Apa prosedur koreksi jika provisional berbeda dari resmi setelah PAK final? |
| BR-PERIOD-003 | Apakah “3 tahun terakhir” selalu rolling 36 bulan? |
| BR-APPOINT-008 | Terminologi final Pengangkatan Pertama vs Perpindahan dari Jabatan Lain |
| BR-KJ-005 | Jika gagal Ukom, apakah PAK dapat dipakai ulang? |
| BR-BAL-003 | Saldo/jenjang baru efektif pada SK atau TMT? |

Pending rules tidak boleh diperlakukan Codex sebagai fakta final.
