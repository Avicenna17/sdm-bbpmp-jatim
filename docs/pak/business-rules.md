# Business Rules
## Sistem PAK Konversi BBPMP Provinsi Jawa Timur

**Versi:** 1.0  
**Tanggal:** 17 Oktober 2026  
**Status:** Draft untuk review implementasi

**Pembaruan 23 Oktober 2026:** keputusan client untuk Phase 02 dicatat pada BR-SKP-002/004/005/006/009/010, BR-JAB-001, BR-REGIME-001, BR-DATA-001, BR-REF-001, dan BR-KONV-004. Status APPROVED pada pembaruan ini berarti konfirmasi bisnis client, bukan klaim verifikasi regulasi baru. Prioritas regulasi resmi tetap berlaku; checkpoint ini hanya dokumentasi.

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

Jika Evaluasi Akhir resmi untuk tahun bersangkutan tersedia, gunakan predikat Evaluasi Akhir sebagai sumber utama, termasuk untuk subset bulan dalam tahun yang sama. Sumber ini mengalahkan triwulan. Simpan coverage asli sumber dan rentang bulan konversi secara terpisah; annual-for-subset bukan lagi PENDING BUSINESS DECISION.

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

Segmentasi mengikuti prioritas BR-SKP-002/009, bukan otomatis membagi setiap triwulan menjadi sumber tersendiri. Contoh: Januari–Oktober menggunakan Q3 jika Evaluasi Akhir/Q4 belum tersedia; coverage asli Q3 tetap Juli–September, sedangkan rentang penerapannya Januari–Oktober dicatat terpisah. Rentang lintas tahun tetap memisahkan sumber setiap tahun.

## BR-SKP-005 — Tidak boleh double counting
**Status:** SYSTEM

Bulan/periode yang sama tidak boleh dihitung dua kali pada satu kalkulasi/run PAK Case, termasuk preview. Satu bulan hanya boleh mendapat satu sumber predikat terpilih. Record annual/triwulan boleh coexist dalam penyimpanan, tetapi tidak boleh sama-sama memberikan predikat pada bulan yang sama dalam kalkulasi.

Jika periode Jan-Jun telah digunakan dari evaluasi periodik, sistem tidak boleh menjumlahkan kembali Jan-Jun dari Evaluasi Akhir untuk case yang sama.

## BR-SKP-006 — Fallback tanpa provisional terpisah
**Status:** RESOLVED / APPROVED

Tidak ada konsep provisional source terpisah pada alur SKP Phase 02. Gunakan Evaluasi Akhir jika tersedia, termasuk subset tahun; jika belum tersedia gunakan triwulan terakhir relevan (Januari–Oktober memakai Q3). Jika sumber relevan tidak tersedia, lakukan review tanpa membuat predikat provisional. BR-SKP-002/005/009 mengatur prioritas dan satu sumber per bulan.

Untuk retensi bukti provisional historis dari desain/proses sebelumnya, metadata berikut tetap dipertahankan; ini bukan kontrak input provisional baru:

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

## BR-SKP-008 — Evaluasi Akhir terbit setelah calculation triwulan
**Status:** PENDING BUSINESS DECISION — kebijakan refresh/revision calculation lama

**RESOLVED:** fallback SKP adalah Evaluasi Akhir (juga subset tahun), atau triwulan terakhir relevan jika Evaluasi Akhir belum tersedia; Januari–Oktober memakai Q3. Tidak ada provisional source terpisah. Satu bulan hanya satu sumber predikat.

**PENDING:** jika conversion/draft telah dihitung memakai triwulan lalu Evaluasi Akhir tersedia kemudian, kebijakan refresh/revision terhadap calculation lama belum dikunci, termasuk kapan dan bagaimana penggantian harus dilakukan. Phase 02 tidak melakukan auto-update/recalculation atau otomatis mengganti run lama. Source evidence, predikat, coverage, range penerapan dan provenance saat calculation tetap dicatat. Kontrak teknis revision chain tidak dengan sendirinya mengesahkan kebijakan bisnis penggantian ini.

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
**Status:** APPROVED — konfirmasi client 23 Oktober 2026

**CALCULATION:** simpan dan hitung dengan presisi penuh (exact rational pada engine). Jangan membulatkan setiap baris/komponen. Jumlahkan hasil presisi penuh, kemudian lakukan rounding hanya pada output/total akhir, maksimal tiga angka desimal dengan ROUND_HALF_UP. Jika digit berikutnya tepat 5, digit terakhir yang dipertahankan dinaikkan.

**DISPLAY:** hapus trailing zero yang tidak signifikan dan gunakan koma desimal. Representasi display baris bukan nilai calculation dan tidak boleh digunakan sebagai operand total; formatting tidak mengubah nilai yang dihitung/disimpan. Pembatasan desimal untuk presentation tidak membulatkan komponen dalam calculation.

```text
100.000 -> 100
18.750 -> 18,75
12.500 -> 12,5
20.830 -> 20,83
20.833 -> 20,833
0.500 -> 0,5
0.000 -> 0
18.7544 -> 18,754
18.7545 -> 18,755
18.7546 -> 18,755
```

Kebijakan terpusat dan berversi. Ini mengunci BR-KONV-004, tetapi tidak memperkenalkan finalisasi/penetapan PAK pada Phase 02; run tetap PREVIEW/REQUIRES_REVIEW/SUPERSEDED.

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

**Klarifikasi client Phase 02C — OD-11 RESOLVED (setelah audit baseline `7c5b6f8`):** bukan klaim authority/verifikasi regulasi baru; status SYSTEM rule ini tetap. Numeric orchestration hanya memakai explicit PakRuleSet milik PakCase. Kedua tanggal harus diketahui dan validity inklusif: `valid_from <= requested_start AND requested_end <= valid_until`. NULL berarti UNKNOWN, bukan open-ended.

Unknown boundary atau request partly/wholly outside validity menghasilkan `RULE_VALIDITY_REQUIRES_REVIEW` untuk seluruh request tanpa numeric calculation dan dengan exact total NULL. Jangan memilih latest/versi lain berdasarkan tanggal, fallback, split antarversi, menghitung valid subset/interior months, silent month drop, atau fabricated zero. Diagnostic save REQUIRES_REVIEW diperbolehkan hanya jika seluruh authoritative-save gates lain sukses.

Urutan: authoritative case/explicit rule identity → rule existence/status/integrity → range/containment dan OD-03 coverage → validity gate → child resolution → numeric calculation. Validity failure short-circuits child numeric resolution; jangan mengumpulkan unrelated missing-child issues setelah global failure. Full-month diagnostic periods tetap mengikuti approved 02C.4 contract, tanpa cardinality baru; partial OD-03 tetap tanpa fabricated months/count/periods.

Setelah validity lolos dan applicable regime mengizinkan current-rule lookup, exact predicate/level lookup tanpa alias, label mapping, substitution atau tie-break tetap berlaku. LEGACY memakai `LEGACY_REGIME_REQUIRES_REVIEW` menurut BR-REGIME-001/OD-12, bukan missing rule/level karena lookup sengaja dilewati. `PREDICATE_RULE_NOT_FOUND`, `COEFFICIENT_RULE_NOT_FOUND`, dan `MISSING_FUNCTIONAL_LEVEL` menghasilkan diagnostic affected line dan incomplete total NULL. Code terakhir hanya untuk position resolution yang sukses tetapi tidak menyediakan canonical functional_level_id; gap/overlap tetap memakai position issue codes. Integrity/rejection (`RULE_INTEGRITY_ERROR`, `RULE_SET_NOT_USABLE`, `RULE_SET_NOT_FOUND`, `INVALID_RULE_INPUT`) tidak mengotorisasi authoritative contradictory run. Detail outcome dan safe issue context mengikuti [OD-11 pada plan Phase 02C](plans/phase-02c-resolution-conversion-runs.md#od-11--resolved-rule-validity-dan-child-resolvability).

DRAFT unused/used tetap resolvable; first use mengunci editing, bukan menciptakan ACTIVE/PUBLISHED. Child rules tidak mempunyai inactive lifecycle; inactive FunctionalLevel tetap historical-resolvable. Used version dengan NULL validity tetap readable tetapi tidak numerically usable; remediasi melalui explicit new/copy version dengan validity lengkap sebelum first use dan authorized PakCase lifecycle bila tersedia/approved, tanpa unlock atau automatic replacement. Historical snapshots tetap immutable; preview read-only dan save/recalculation revalidate tanpa implicit rewrite. OD-07/OD-08 tidak diselesaikan oleh rule ini.

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
| BR-SKP-008 | Bagaimana kebijakan refresh/revision calculation triwulan lama ketika Evaluasi Akhir kemudian tersedia? Fallback sumber sudah resolved; tidak ada auto-update calculation lama. |
| BR-PERIOD-003 | Apakah “3 tahun terakhir” selalu rolling 36 bulan? |
| BR-APPOINT-008 | Terminologi final Pengangkatan Pertama vs Perpindahan dari Jabatan Lain |
| BR-KJ-005 | Jika gagal Ukom, apakah PAK dapat dipakai ulang? |
| BR-BAL-003 | Saldo/jenjang baru efektif pada SK atau TMT? |

Pending rules tidak boleh diperlakukan Codex sebagai fakta final.

## Klarifikasi teknis Foundation

Klarifikasi teknis BR-KJ-006 tanpa perubahan status: sumber published Peta Jabatan adalah published_batch_id pada position_datasets, dengan position_version/published_at pada import_batches. Integrasi berikutnya menyimpan versi/nilai sumber. Business rule PENDING tetap.

# U. Keputusan client Phase 02 — 23 Oktober 2026

## BR-SKP-009 — Triwulan terakhir relevan
**Status:** APPROVED

Jika Evaluasi Akhir belum tersedia, gunakan hasil SKP triwulan terakhir yang relevan untuk periode yang diminta. Januari–Oktober ketika Q4/Evaluasi Akhir belum tersedia menggunakan predikat Q3 untuk Januari–Oktober, bukan Q1+Q2+Q3 ditambah provisional Oktober. Coverage dokumen Q3 tidak diubah; simpan rentang penerapan dan alasan pemilihan secara terpisah. Jika sumber relevan tidak tersedia atau tidak dapat dibuktikan, tahan kalkulasi untuk review; jangan menebak predikat.

## BR-SKP-010 — Beberapa record Evaluasi Akhir
**Status:** APPROVED

Jika terdapat beberapa record Evaluasi Akhir akibat perubahan jabatan, gunakan record/predikat terkait jabatan dengan TMT terbesar/terbaru yang berlaku pada bulan yang dihitung, sesuai BR-JAB-001. Jika ada lebih dari satu perubahan dalam bulan itu, gunakan TMT paling akhir bulan tersebut. Jangan memilih berdasarkan urutan record, ID database, tanggal import, waktu upload, atau urutan Excel. Simpan kaitan evaluasi dengan histori terverifikasi dan provenance pemilihannya; record lain dipertahankan. Jika hubungan/TMT ambigu, lakukan review, bukan tie-break berdasarkan urutan input.

## BR-JAB-001 — Jabatan/jenjang terbaru dan basis bulan
**Status:** APPROVED

**Klarifikasi OD-04 — RESOLVED:** latest-TMT whole-month semantics di bawah berlaku setelah continuity/integrity seluruh requested calendar month terbukti dari histories canonical person yang verified dan non-superseded. `tmt_start` dan `tmt_end` inclusive; null end open-ended. Successor contiguous mulai sehari setelah predecessor end, bukan pada tanggal end yang sama. Gap menghasilkan `MISSING_POSITION_HISTORY`; overlap/equal-TMT conflict menghasilkan `AMBIGUOUS_POSITION_HISTORY`, tanpa assignment authoritative untuk bulan terdampak. Jangan menutup gap secara retroaktif, memotong open-ended history implicit, atau memakai latest TMT untuk mengabaikan overlap. Setelah coverage penuh tanpa gap/overlap terbukti, TMT tanggal 1/tengah/akhir bulan tetap berlaku untuk seluruh bulan tanpa prorata/split coefficient. Contract dan contoh lengkap: [OD-04 Phase 02C](plans/phase-02c-resolution-conversion-runs.md#od-04--resolved-history-endgap-dalam-bulan). Ini konfirmasi bisnis client, bukan klaim verifikasi regulasi baru; status APPROVED tetap.

Untuk setiap bulan yang dihitung, pilih jabatan/jenjang dengan TMT terbesar/terbaru yang berlaku pada bulan tersebut. Jika lebih dari satu perubahan terjadi dalam bulan yang sama, gunakan record dengan TMT paling akhir pada bulan tersebut. Jabatan yang baru berlaku pada bulan berikutnya tidak diterapkan mundur ke bulan sebelumnya. Pemilihan bukan berdasarkan urutan record, ID database, tanggal import, waktu upload, atau urutan Excel. Acuan TMT JABATAN -> SK dari data kepegawaian/DUK terverifikasi; simpan TMT asli, referensi SK, sumber, dan hasil resolusi per bulan.

PAK menggunakan basis bulan, bukan hari. TMT perubahan pada pertengahan atau akhir bulan tetap memperlakukan seluruh bulan tersebut dengan jabatan/jenjang terbaru pada bulan itu. Tidak ada prorata hari atau pembagian koefisien lama/baru di dalam satu bulan. Antarbulan dapat memakai jenjang berbeda sesuai TMT yang berlaku masing-masing; segmentasi mengikuti hasil resolusi bulanan. Ini memperjelas bahwa "terbaru" bukan satu jenjang terakhir untuk seluruh rentang lintas bulan, dan wajib mempunyai trace serta test.

Keputusan ini tidak mengubah tanggal histori asli, tidak menciptakan histori overlap, dan tidak menentukan trigger perubahan administratif/saldo pada BR-BAL-003. Requested range batas hari parsial telah diputuskan melalui **OD-03 — RESOLVED** pada [Phase 02C implementation plan](plans/phase-02c-resolution-conversion-runs.md#od-03--resolved-partial-requested-range): boundary perhitungan langsung wajib full-month dan contained dalam PakCase period. Partial start/end mempertahankan tanggal asli dan menghasilkan `PARTIAL_MONTH_REQUIRES_REVIEW`, tanpa prorata, normalisasi/ekspansi tanggal, silent dropping, penetapan partial month sebagai zero, atau complete counted-month list/count dari bagian tengah request saja. Ini terpisah dari TMT tengah/akhir bulan yang tetap mengikuti whole-month semantics di atas; bukan perubahan status BR-JAB-001 atau penyelesaian OD-04/BR-PERIOD-003.

## BR-REGIME-001 — Kelompok mekanisme berdasarkan TMT Jabatan
**Status:** APPROVED

- **TMT Jabatan < 2023-01-01 = LEGACY / AK Integrasi.** AK Integrasi merupakan nilai existing dari data/rekap client, tidak dihitung ulang oleh Conversion Engine Phase 02 dan tidak dijumlahkan ke total conversion.
- **TMT Jabatan >= 2023-01-01 = PERFORMANCE CONVERSION / konversi predikat kinerja.**
- Jangan membuat calculator aturan lama pada Phase 02.

Tanggal tersebut adalah keputusan bisnis saat ini, bukan asumsi aturan pemerintah tidak berubah. Provenance TMT, regime, referensi, dan rule/version harus tersimpan dan dapat diaudit. Tetap satu rule set dipilih eksplisit; tidak memperkenalkan automatic rule-set resolver. Jika kasus kelompok lama memerlukan mekanisme di luar konversi biasa, lakukan review tanpa mengimplementasikan calculator aturan lama pada Phase 02.

**Klarifikasi client 02C.5.2 / OD-12 — RESOLVED:** canonical outcome adalah `LEGACY_REGIME_REQUIRES_REVIEW` ketika authoritative evidence menunjukkan **TMT Jabatan SK < 2023-01-01**. Resolved history/provenance dapat menyediakan proof, tetapi labels bukan identity keys dan `history.tmt_start` tidak otomatis disamakan dengan TMT Jabatan SK tanpa accepted documentary equivalence. Status APPROVED BR-REGIME-001 tidak berubah; ini bukan klaim authority regulasi baru.

Affected LEGACY line review-saveable hanya jika semua authoritative save gates lolos: run REQUIRES_REVIEW, exact line result NULL, complete parent total NULL. Hentikan current-regime child-rule resolution untuk line itu; jangan emit missing predicate/coefficient hanya karena lookup sengaja tidak dilakukan. Non-LEGACY lines boleh dihitung diagnostically bila valid, tetapi bukan complete authoritative subtotal. Jangan fabricate zero, drop month/line, atau otomatis consume/transfer/backfill/add stored AK Integrasi maupun reinterpret nilai lama sebagai output konversi baru. Coverage dan diagnostic persistence tetap mengikuti 02C.4 tanpa schema/cardinality baru. Penggunaan AK Integrasi kelak adalah lifecycle/business handling terpisah. Detail precedence dan delivery mengikuti [OD-12 pada Phase 02C plan](plans/phase-02c-resolution-conversion-runs.md#od-12--resolved-canonical-legacy-review-outcome).

**Klarifikasi client OD-13 — RESOLVED:** documentary TMT Jabatan SK terbukti hanya dari selected authoritative EmployeePositionHistory milik authoritative person, verified (`verified_by`/`verified_at`), non-superseded, selected menurut OD-04, dengan `tmt_start` tersedia serta `sk_number` dan `source_ref` documentary reference tersedia/nonblank. Untuk row tersebut saja, `employee_position_histories.tmt_start` diterima sebagai effective TMT Jabatan SK **untuk regime classification only**. Ini memenuhi documentary equivalence terbatas yang diminta OD-12, bukan generalisasi setiap history. `sk_date` tetap document date, bukan effective TMT dan tidak digunakan untuk boundary 2023. `tmt_start` tanpa syarat tersebut, `sk_date`, `source_position_tmt`, free-text reference sendiri, position/functional-level labels tidak cukup sebagai proof.

Proven TMT < 2023-01-01 tetap LEGACY sesuai OD-12; proven TMT >= 2023-01-01 mengizinkan CURRENT child-rule resolution subject to other gates. Resolved position/history tanpa cukup proof menghasilkan `REGIME_EVIDENCE_REQUIRES_REVIEW`, tidak diklasifikasikan LEGACY/CURRENT: affected line non-numeric, line result dan complete total NULL, conditional review-saveable, coverage/bounded provenance dipertahankan. Jangan jalankan child lookup atau emit missing predicate/coefficient karena lookup sengaja dilewati; jangan fabricate zero, drop month/line, atau consume/transfer/backfill/add AK Integrasi. Existing position gap/overlap codes dan diagnostic schema tetap. [OD-13](plans/phase-02c-resolution-conversion-runs.md#od-13--resolved-tmt-jabatan-sk-evidence-contract) menetapkan evidence gate setelah source/position resolution dan sebelum regime/suitability; approval status BR-REGIME-001 dan semantik OD-12 tidak berubah.

## BR-DATA-001 — Atribut personnel dan default organisasi
**Status:** APPROVED

Kebutuhan dokumen resmi mendatang: KARPEG/Nomor Seri Karpeg, TEMPAT LAHIR, TGL LAHIR, TMT PANGKAT/GOL, serta TMT JABATAN -> SK dari DUK/full personnel source. Catat sumber/batch/versi atau dokumen, field asli, tanggal acuan, actor/waktu verifikasi, dan nilai yang digunakan. TMT pangkat dan TMT jabatan tetap berbeda; sk_date, sk_number, dan tmt tidak disamakan. Data belum tersedia tidak ditebak.

Default Unit Kerja: **Balai Besar Penjaminan Mutu Pendidikan Provinsi Jawa Timur**.

Default Instansi: **Kementerian Pendidikan Dasar dan Menengah**.

Default harus diberi provenance sebagai default organisasi client, bukan diklaim sebagai bukti organisasi historis yang terverifikasi. Snapshot historis tetap dipertahankan. Checkpoint ini tidak mengubah importer, mapping sumber executable, atau schema.

## BR-REF-001 — Rule engine versus guidance administrator
**Status:** APPROVED

Materi dokumen resmi/PPT perlu tersedia sebagai referensi Admin PAK dengan judul, sumber, versi/tanggal, dan lingkup penggunaan. Bedakan:

- Conversion Engine Phase 02: rule pemilihan predikat, jabatan/jenjang, faktor dan koefisien dari master versi eksplisit, formula konversi, serta rounding.
- Reference/guidance: ketentuan umum pemberian AK perpindahan ke dalam JF, penjelasan perhitungan konversi, tabel kebutuhan kenaikan pangkat/jabatan model konversi, dan tabel lain yang telah diidentifikasi dari dokumen sumber.
- Phase berikutnya: calculator perpindahan, AK Dasar, target KP/KJ, integrasi/akumulasi dan penetapan sesuai scope masing-masing.

Ketersediaan tabel sebagai referensi tidak otomatis mengaktifkannya sebagai calculator. Status REFERENCE angka master tidak otomatis menjadi APPROVED hanya karena requirement guidance ini. Checkpoint hanya mendokumentasikan kebutuhan, tidak membuat UI/file ingestion/calculator.
