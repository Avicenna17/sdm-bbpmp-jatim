# Test Cases
## Sistem PAK Konversi BBPMP Provinsi Jawa Timur

**Versi:** 1.0  
**Tanggal:** 17 Oktober 2026

**Pembaruan spesifikasi:** 23 Oktober 2026, konfirmasi client Phase 02. Ini test specification dokumenter, bukan test executable. Bagian Phase 03+ tetap referensi fase berikutnya.

---

## 1. Tujuan

Dokumen ini menjadi acceptance/golden test untuk:

- calculation engine;
- period resolver;
- accumulation;
- determination;
- workflow;
- authorization;
- histori.

Setiap perubahan business rule yang memengaruhi expected result harus memperbarui test terkait.

---

# 2. Convention

Nilai AK menggunakan decimal.

Display contoh:

```text
4.166666... -> 4.167
20.833333... -> 20.833
```

BR-KONV-004 telah APPROVED.

**CALCULATION:** simpan/hitung presisi penuh, jangan membulatkan setiap baris, jumlahkan hasil presisi penuh; rounding hanya output/total akhir dengan ROUND_HALF_UP maksimum tiga angka desimal.

**DISPLAY:** hapus trailing zeros dan gunakan koma desimal: 100.000 -> 100; 18.750 -> 18,75; 12.500 -> 12,5; 20.833 -> 20,833. Formatting tidak mengubah calculation. Angka bertitik dalam formula adalah notasi internal; display bukan operand total.

---

# 3. Golden Test — Nurmaulida

## TC-PAK-001 — PAK Nurmaulida Sitoresmi

**Priority:** CRITICAL  
**Source:** dokumen PAK fisik BBPMP Jatim

**Koreksi client:** dokumen terbaru `PAK KONVERSI Nurmaulida Sitoresmi, S.S..docx` menegaskan AK Dasar 100 dan AK Konversi Baru 75 menghasilkan total kumulatif 175. AK Dasar tidak dicatat kembali sebagai AK Konversi Lama.

**Scope Phase 02:** numeric fixture saja sampai dasar resmi mapping jabatan/jenjang dapat dibuktikan. Jangan membuat mapping JF fiktif untuk mendapatkan koefisien 25. Resolusi produksi tetap menggunakan data terverifikasi. Expected Akumulasi/PAK di bawah hanya untuk Phase berikutnya.

### Given

Pegawai:

```text
Pangkat/Golongan : Penata Tk.I / III/d
Jabatan          : Penelaah Teknis Kebijakan
Koefisien yang digunakan pada dokumen : 25
Predikat         : Baik (100%)
```

Periode Konversi:

```text
1 Nov 2023 - 31 Des 2023
1 Jan 2024 - 31 Des 2024
1 Jan 2025 - 31 Des 2025
1 Jan 2026 - 31 Okt 2026
```

### Expected Conversion

#### 2023

```text
2 / 12 × 1.00 × 25
= 4.166666...
Display = 4.167
```

#### 2024

```text
12 / 12 × 1.00 × 25
= 25
```

#### 2025

```text
12 / 12 × 1.00 × 25
= 25
```

#### 2026

```text
10 / 12 × 1.00 × 25
= 20.833333...
Display = 20.833
```

### Expected New Conversion Total

```text
(2/12 × 25) + 25 + 25 + (10/12 × 25)
= 75 (exact)
Display total = 75
```

Display line 2023 = 4,167 dan 2026 = 20,833 tidak dipakai untuk penjumlahan total. Kesamaan total 75 dari baris bulat pada contoh ini tidak membuktikan strategi penjumlahan tersebut benar; lihat TC-PAK-035.

### Expected Akumulasi

Dokumen Akumulasi memisahkan AK Dasar dari AK Konversi Baru:

```text
AK Dasar         = 100
AK Konversi Baru = 75
Jumlah AK        = 175
```

### Expected PAK

Dokumen Penetapan:

```text
AK Dasar yang diberikan — Lama = 100
AK Konversi — Lama             = tidak ada / 0
AK Konversi — Baru             = 75
Jumlah Angka Kredit Kumulatif:
  Lama                        = 100
  Baru                        = 75
  Total                       = 175
```

AK Dasar != AK Integrasi/legacy != AK Konversi. Pada golden case ini, angka 100 adalah AK Dasar, bukan AK Konversi Lama atau AK Integrasi. Jangan memasukkannya kembali sebagai komponen konversi; total kumulatif menghitung AK Dasar hanya sekali.

### Assertions

- [ ] Empat conversion line terbentuk.
- [ ] Tidak ada overlap.
- [ ] Total conversion baru = 75.
- [ ] Akumulasi = 175.
- [ ] AK Dasar Lama = 100; AK Konversi Lama tidak ada / 0; AK Konversi Baru = 75.
- [ ] PAK kumulatif: Lama = 100, Baru = 75, Total = 175.
- [ ] AK Dasar tidak dihitung ulang sebagai AK Konversi Lama atau diklasifikasikan sebagai AK Integrasi/legacy.
- [ ] Calculation trace tersimpan.
- [ ] Final snapshot tidak berubah bila master coefficient kemudian diedit.

---

# 4. Tahunan

## TC-PAK-002 — Ahli Pertama Sangat Baik Full Year

### Given

```text
Jenjang     = Ahli Pertama
Coefficient = 12.5
Predikat    = Sangat Baik
Factor      = 1.50
Periode     = 12 bulan
```

### Expected

```text
1 × 1.50 × 12.5 = 18.75
```

Assertions:

- [ ] Result = 18.75.
- [ ] Rule berasal dari master.
- [ ] Bukan hard-code di calculator.

---

# 5. Periode Jan–Mei dengan Triwulan Terakhir Relevan

## TC-PAK-003 — Prioritas Sumber Jan–Mei

### Given

Jenjang:

```text
Ahli Muda
Coefficient = 25
```

SKP (Evaluasi Akhir belum tersedia; Q2 resmi telah tersedia dan relevan untuk rentang permintaan):

```text
Jan-Mar = Baik (1.00)
Apr-Jun = Sangat Baik (1.50)
```

PAK membutuhkan:

```text
Jan-Mei
```

### Expected Segment

```text
Jan-Mei = 5 bulan memakai Q2 Sangat Baik
Coverage asli sumber Q2 tetap Apr-Jun
```

### Expected AK

```text
5/12 × 1.50 × 25 = 15.625
Display total = 15,625
```

Assertions:

- [ ] Triwulan terakhir relevan dipakai sesuai BR-SKP-009; tidak menjumlahkan Q1 dan Q2 sebagai dua predikat untuk periode yang sama.
- [ ] Juni tidak dihitung.
- [ ] Coverage sumber dan range penerapan terpisah; setiap bulan hanya satu predikat.

---

# 6. Jan–Oktober dengan Q3

## TC-PAK-004 — Q3 Menggantikan Kebutuhan Provisional Oktober

### Given

```text
Jenjang = Ahli Muda
Coefficient = 25
Q3 official = Baik
Q4 dan Evaluasi Akhir belum tersedia
Requested range = Jan-Okt
```

### Expected

```text
10/12 × 1 × 25 = 20.833333...
Display total = 20,833
```

Assertions:

- [ ] Q3 menjadi sumber predikat untuk Jan–Okt; coverage asli Jul–Sep tidak diubah.
- [ ] Tidak membuat record provisional Oktober.
- [ ] Source reference, alasan resolusi BR-SKP-009 dan range penerapan tersimpan pada trace.
- [ ] Tidak ada bulan overlap atau nilai display yang dipakai untuk total.

---

# 7. Evaluasi Akhir Tersedia Setelah Calculation Triwulan

## TC-PAK-005 — Kebijakan Refresh/Revision Calculation Lama

**Rule:** BR-SKP-008. **Status:** PENDING BUSINESS DECISION untuk kebijakan refresh/revision; safeguard non-mutation wajib pada Phase 02.

### Given

Conversion/draft telah dihitung memakai Q3 dengan source evidence dan snapshot tersimpan. Evaluasi Akhir kemudian tersedia, baik predikat sama maupun berbeda.

### Expected

- [ ] Calculation lama, angka, total, source ID, predikat dan evidence saat calculation tetap utuh.
- [ ] Tidak ada auto-update, recalculation, atau successor otomatis hanya karena annual tersedia.
- [ ] Fallback untuk kalkulasi baru tetap annual; tidak membuat provisional source.
- [ ] Kebijakan kapan/bagaimana refresh/revision run lama tetap pending; jangan menganggap technical replacement chain sebagai persetujuan bisnis.
- [ ] Correction chain sumber dan replacement chain run tetap sesuai kontrak teknis, tanpa kehilangan bukti lama.

---

# 8. Overlap

## TC-PAK-006 — Mencegah Double Counting

### Given

Conversion line existing:

```text
Jan-Jun 2026
```

User mencoba menambah:

```text
Jan-Dec 2026
```

ke kalkulasi/run case yang sama, termasuk preview Phase 02.

### Expected

- [ ] validation error overlap;
- [ ] save run ditolak; tidak ada dua sumber memberi predikat pada bulan sama;
- [ ] system menawarkan segment yang belum dihitung bila implementasi mendukung.

---

# 9. AK Integrasi

## TC-PAK-007 — Integrasi + Konversi Jan–Jun

**Scope:** penjumlahan integrasi dan konversi berikut adalah Phase 03. Phase 02 hanya menghasilkan conversion 12.5; pemisahan diuji pada TC-PAK-034.

### Given

```text
AK Integrasi = 190
Jenjang Ahli Muda
Coefficient = 25
Jan-Jun = Baik (100%)
```

### Expected

```text
6/12 × 1 × 25
= 12.5

Total
= 190 + 12.5
= 202.5
```

Assertions:

- [ ] integration sebagai komponen terpisah;
- [ ] source dapat ditelusuri;
- [ ] result 202.5.

---

# 10. KP Saja

## TC-PAK-008 — AK Memenuhi KP, Belum KJ

### Given

```text
Jenjang Ahli Muda
KP target = 100
KJ target = 200
Cumulative AK = 125
```

### Expected

```text
kp_ak_met = true
kj_ak_met = false
recommendation = KP
```

Catatan:

final administrative KP masih membutuhkan eligibility check lain.

---

# 11. KJ dari Sisi AK

## TC-PAK-009 — AK Memenuhi KJ

### Given

```text
Jenjang Ahli Muda
KJ target = 200
Cumulative AK = 205
```

### Expected

```text
kj_ak_met = true
```

Workflow:

```text
KJ_ELIGIBLE
→ WAITING_COMPETENCY_TEST
```

Tidak langsung mengubah jenjang aktif.

---

# 12. KJ Gagal Ukom

## TC-PAK-010 — Competency Failed

### Given

```text
KJ eligible
Ukom result = FAILED
```

### Expected

- [ ] status = COMPETENCY_FAILED;
- [ ] jenjang existing tidak berubah;
- [ ] PAK tetap tersimpan;
- [ ] hasil Ukom tersimpan.

Reuse PAK pada Ukom selanjutnya masih pending business rule.

---

# 13. KJ Lulus Ukom

## TC-PAK-011 — Competency Passed

### Given

```text
KJ eligible
Ukom = PASSED
```

### Expected

```text
COMPETENCY_PASSED
→ WAITING_DECISION
→ WAITING_SK
```

Belum `EFFECTIVE` sampai event efektif terpenuhi.

---

# 14. KP + KJ

## TC-PAK-012 — KJ Didahulukan

### Given

Pegawai memenuhi rule KP dan KJ.

### Expected

- [ ] recommendation = KP_KJ;
- [ ] workflow KJ dimulai dahulu;
- [ ] KP tidak dinyatakan effective sebelum dependency KJ selesai;
- [ ] AK tidak di-reset sebelum proses yang membutuhkan AK selesai.

---

# 15. SK dan TMT

## TC-PAK-013 — SK Hari Ini, TMT Bulan Depan

### Given

```text
SK date = 20 Nov 2026
TMT     = 1 Des 2026
```

### Proposed Expected

Pada 25 Nov:

```text
status = WAITING_TMT
old level masih effective
```

Pada/efektif 1 Des:

```text
new level effective
```

**Status test:** PENDING final confirmation BR-BAL-003.

Test harus ditandai skipped/pending sampai rule dikunci.

---

# 16. KJ dan Reset History

## TC-PAK-014 — Reset Tanpa Menghapus History

### Given

Siklus lama:

```text
Closing AK = 225
KJ target  = 200
Excess     = 25
```

Client rule:

kelebihan tidak dibawa ke jenjang baru.

### Expected

- [ ] cycle lama closing balance tetap 225 di history;
- [ ] excess 25 tetap dapat dilihat di audit/history;
- [ ] cycle baru opening balance mengikuti rule (contoh 0);
- [ ] tidak ada destructive overwrite.

---

# 17. Perpindahan: Pelaksana III/c -> Ahli Pertama

## TC-PAK-015 — Fixed Transfer Table

### Given

```text
Origin type = Pelaksana
Origin grade = III/c
Target level = Ahli Pertama
Condition = pangkat tidak sesuai jenjang (sesuai tabel referensi)
```

### Expected Reference Value

```text
AK = 100
```

Assertions:

- [ ] rule berasal dari `ak_transfer_rules`;
- [ ] bukan if/else hard-coded;
- [ ] rule set/version tersimpan pada result.

Sebelum produksi, mapping ini harus divalidasi terhadap master final client.

---

# 18. AK Dasar

## TC-PAK-016 — Ahli Muda III/d

### Given

Reference table:

```text
Level = Ahli Muda
Grade = III/d
AK Dasar = 100
```

### Expected

`basic_ak = 100`

Nilai harus berasal dari `ak_basic_rules`.

---

# 19. Pengajuan Pegawai

## TC-PAK-017 — JF Boleh Mengajukan

### Given

User terhubung ke person dengan current position = JF.

### Action

Klik `Ajukan Pemeriksaan KP/KJ`.

### Expected

- [ ] name otomatis;
- [ ] NIP otomatis;
- [ ] hanya message dapat diisi;
- [ ] submission dibuat;
- [ ] SDM mendapat daftar pengajuan;
- [ ] submission belum membuat keputusan eligibility.

---

# 20. Pelaksana Tidak Menggunakan Pengajuan Personal

## TC-PAK-018

### Given

Current position type = Pelaksana.

### Expected

- [ ] action pengajuan JF tidak tersedia;
- [ ] warning engine masih dapat menandai person untuk review SDM.

---

# 21. Authorization Pegawai

## TC-PAK-019 — Person A Tidak Boleh Melihat Person B

### Given

```text
User A -> person_id A
User B -> person_id B
```

### Expected

User A:

- [ ] dapat melihat PAK A;
- [ ] tidak dapat query/view PAK B;
- [ ] tidak dapat melihat SKP B;
- [ ] tidak dapat download dokumen B.

Test harus mencakup policy/query scope, bukan hanya hidden navigation.

---

# 22. Panel Permission

## TC-PAK-020 — Admin Existing Tanpa pak.access

### Expected

- [ ] `/admin` tetap dapat diakses sesuai permission lama;
- [ ] `/admin/pak` ditolak;
- [ ] resource PAK tidak tampil pada workspace SDM dan direct URL ditolak tanpa permission PAK.

## TC-PAK-021 — Admin PAK

- [ ] `/admin/pak` dapat diakses, `/admin` menuju home PAK jika hanya satu modul;
- [ ] tidak otomatis memperoleh user.manage jika role tidak memiliki permission tersebut.

---

# 23. Snapshot DUK Tidak Merusak PAK

## TC-PAK-022 — Reimport DUK Setelah PAK Final

### Given

PAK final menyimpan:

```text
Pangkat = Penata Tk.I
Grade = III/d
Jabatan = X
```

Kemudian DUK bulan berikutnya berubah:

```text
Pangkat/Jabatan berbeda
```

### Expected

- [ ] PAK lama tetap menampilkan snapshot lama;
- [ ] foreign key PAK tidak putus;
- [ ] current person identity tetap sama.

---

# 24. Rule Versioning

## TC-PAK-023 — Koefisien Berubah di Masa Depan

### Given

PAK final menggunakan:

```text
Coefficient 25
Rule Set V1
```

Master V2 kemudian menjadi nilai berbeda.

### Expected

- [ ] PAK lama tetap 25 pada snapshot;
- [ ] recalc tidak terjadi otomatis;
- [ ] PAK baru menggunakan V2 jika tanggal berlaku sesuai.

---

# 25. Template Versioning

## TC-PAK-024

### Given

Dokumen final dibuat menggunakan Template V1.

Admin membuat Template V2.

### Expected

- [ ] dokumen lama tetap merujuk V1;
- [ ] regenerate final tidak dilakukan otomatis;
- [ ] dokumen baru dapat memakai V2.

---

# 26. Nomor Dokumen

## TC-PAK-025

### Expected

- [ ] generate dokumen valid walaupun `document_number = null`;
- [ ] template menampilkan ruang nomor kosong;
- [ ] sistem tidak membuat nomor otomatis.

---

# 27. Tanda Tangan

## TC-PAK-026

### Expected

- [ ] dokumen dapat dicetak tanpa digital signature;
- [ ] tidak ada dependency TTE untuk finalisasi calculation.

---

# 28. Decimal Precision

## TC-PAK-027

Input:

```text
10/12 × 1 × 25
```

Expected raw:

```text
20.833333333...
```

Expected display:

```text
20.833
```

Tidak boleh muncul:

```text
20.8329999997
```

---

# 29. Transaction Finalization

## TC-PAK-028

Simulasikan error ketika finalizing accumulation/determination.

Expected:

- [ ] transaction rollback;
- [ ] final snapshot lama tidak rusak;
- [ ] case tetap pada status aman;
- [ ] event failure/log tersedia tanpa mengekspos data sensitif.

---

# 30. Critical UAT Matrix

Sebelum production minimal UAT:

| ID | Scenario | Wajib |
|---|---|---|
| TC-PAK-001 | Nurmaulida | Ya |
| TC-PAK-002 | Full year | Ya |
| TC-PAK-003 | Jan-Mei | Ya |
| TC-PAK-004 | Jan–Oktober memakai Q3 tanpa provisional | Ya |
| TC-PAK-006 | Overlap | Ya |
| TC-PAK-007 | Integrasi | Ya |
| TC-PAK-008 | KP only | Ya |
| TC-PAK-009 | KJ | Ya |
| TC-PAK-010 | Failed Ukom | Ya |
| TC-PAK-012 | KP+KJ | Ya |
| TC-PAK-015 | Transfer | Ya |
| TC-PAK-019 | Privacy | Ya |
| TC-PAK-022 | Snapshot stability | Ya |
| TC-PAK-023 | Rule versioning | Ya |

---

# 31. Test Levels

Implementasikan:

### Unit Test

- ConversionCalculator;
- RuleResolver;
- PerformancePeriodResolver;
- rounding;
- target determination.

### Feature Test

- Filament/route authorization;
- submission;
- finalize transaction;
- Ukom workflow;
- document generation.

### Integration Test

- existing Person + PAK;
- published Peta Jabatan + eligibility;
- reimport DUK + finalized PAK stability.

### UAT

Dilakukan bersama Tim SDM menggunakan berkas nyata yang telah dianonimkan bila diperlukan.

# 32. Spesifikasi Konfirmasi Client Phase 02 — 23 Oktober 2026

Semua kasus berikut adalah spesifikasi dokumenter. Angka koefisien/faktor sintetis merupakan fixture, bukan pengesahan master produksi. Setiap kasus memeriksa source/rule/version snapshot, server-authoritative calculation, dan status run yang tetap PREVIEW/REQUIRES_REVIEW/SUPERSEDED.

## TC-PAK-029 — Evaluasi Akhir mengalahkan triwulan

**Rules:** BR-SKP-002/005. Given Q3 Baik (1), annual tahun yang sama Sangat Baik (1.5), koefisien fixture 25. Untuk tahun penuh, expected raw 37.5, display 37,5 dari annual. Untuk Nov–Des, expected raw 6.25, display 6,25 dari annual. Annual-subset tidak diberi issue hanya karena subset. Coverage sumber annual tetap satu tahun; requested range terpisah. Pemaksaan pilihan Q3 tidak boleh mengalahkan annual.

## TC-PAK-030 — Tanpa annual, triwulan terakhir relevan

**Rules:** BR-SKP-009. Given Q1 Baik, Q2 Sangat Baik resmi/relevan, annual belum tersedia, request Jan–Mei: gunakan Q2 untuk lima bulan, sesuai TC-PAK-003. Jika tidak ada sumber relevan terverifikasi, hasil ditahan untuk review, bukan predikat rekaan atau provisional otomatis.

## TC-PAK-031 — Jan–Oktober menggunakan Q3

**Rules:** BR-SKP-009/005. Given Q1/Q2 memiliki predikat berbeda, Q3 Baik, Q4/annual belum tersedia. Expected Q3 menjadi satu sumber untuk sepuluh bulan, raw 125/6 dengan koefisien 25, output 20,833. Tidak menjumlahkan predikat Q1/Q2 dan tidak membuat provisional Oktober. Verifikasi coverage Q3 Jul–Sep serta applied range Jan–Okt tersimpan terpisah. Setiap bulan tepat satu sumber, termasuk ketika request mencoba menambahkan annual/triwulan overlap (ditolak sesuai TC-PAK-006).

## TC-PAK-032 — Beberapa Record Annual dan TMT Per Bulan

**Rules:** BR-SKP-010, BR-JAB-001. Given annual terkait jabatan A (TMT 1 Januari), B (TMT 15 Oktober), dan C (TMT 25 Oktober), dengan predikat berbeda dan hubungan ke histori verified. Untuk Januari–September gunakan record terkait jabatan A; untuk seluruh Oktober gunakan C, karena TMT paling akhir yang berlaku dalam Oktober. Ubah urutan record, ID database, tanggal import, dan urutan Excel: pilihan tidak berubah. Jangan menerapkan C ke bulan sebelum Oktober. Jika hubungan/TMT tidak dapat dibuktikan, review tanpa tie-break buatan.

## TC-PAK-033 — Jenjang Per Bulan dan Beberapa Perubahan Dalam Sebulan

**Prasyarat OD-04:** histories fixture verified/non-superseded dan interval inclusive-nya contiguous tanpa gap/overlap untuk seluruh requested months. Setiap predecessor ditutup sehari sebelum successor mulai; latest-TMT whole-month selection dilakukan setelah continuity/integrity terbukti (TC-PAK-040).

**Rule:** BR-JAB-001. Given request Jan–Okt, jenjang lama koefisien fixture 12.5 berlaku Jan–Sep, jenjang baru koefisien 25 TMT 15 Oktober; predikat terpilih Baik. Expected raw = (9/12 × 12.5) + (1/12 × 25) = 275/24; total output 11,458. Seluruh Oktober memakai 25, bukan prorata hari; Januari–September tidak memakai 25 secara retroaktif.

Ulangi TMT baru 1 Oktober dan 31 Oktober: hasil sama. Tambahkan perubahan lain koefisien fixture 37.5 pada TMT 25 Oktober setelah TMT 15 Oktober: seluruh Oktober memakai 37.5; raw total = (9/12 × 12.5) + (1/12 × 37.5) = 12.5, display 12,5. Record dengan ID/urutan Excel/import paling akhir tetapi TMT lebih awal tidak menang. TMT asli dan SK tidak diubah. Tidak menghasilkan PARTIAL_MONTH_REQUIRES_REVIEW hanya karena TMT; requested range parsial mengikuti OD-03 RESOLVED dan TC-PAK-039.

## TC-PAK-034 — Regime TMT dan AK Integrasi existing

**Rules:** BR-REGIME-001, BR-INTEGRASI-002/003. TMT Jabatan < 2023-01-01 = LEGACY / AK Integrasi; TMT Jabatan >= 2023-01-01 = PERFORMANCE CONVERSION / konversi predikat kinerja. Uji tepat batas: 2022-12-31 masuk LEGACY, 2023-01-01 masuk PERFORMANCE CONVERSION. AK Integrasi 190 dari rekap client tetap nilai existing dengan provenance, tidak dihitung ulang engine Phase 02. Jangan membuat calculator aturan lama. Jika konversi biasa yang sah menghasilkan 12.5, keluaran engine hanya 12.5, bukan 202.5. Regime dan TMT disnapshot dengan versi, bukan asumsi regulasi abadi atau pemilihan rule set otomatis.

## TC-PAK-035 — ROUND_HALF_UP total-only

**Rules:** BR-KONV-003/004. Fixture dua komponen raw 1.2344 dan 1.2344: total raw 2.4688, output 2,469. Jika display tiap baris 1,234, jumlah display 2,468 bukan total yang benar dan wajib tidak digunakan. Uji raw total 18.7544 -> 18,754; 18.7545 -> 18,755; 18.7546 -> 18,755. Numerator/denominator dan nilai input tidak berubah oleh formatting. Rekalkulasi dari snapshot presisi penuh menghasilkan nilai identik.

## TC-PAK-036 — Presentation trimming

**Rule:** BR-KONV-004. Expected: 100.000 -> 100; 18.750 -> 18,75; 12.500 -> 12,5; 20.830 -> 20,83; 20.833 -> 20,833; 0.500 -> 0,5; 0.000 -> 0. Maksimal tiga desimal, koma desimal, tanpa trailing zero tidak signifikan. Formatting tidak mengubah nilai tersimpan dan tidak menyebabkan pembulatan komponen sebelum total.

## TC-PAK-037 — Golden Nurmaulida numeric-only

**Rules:** BR-KONV-003/004/005. Expected raw Nov–Dec 2023 = 25/6, 2024 = 25, 2025 = 25, Jan–Oct 2026 = 125/6; jumlah eksak 75, output 75. Detail display 4,167; 25; 25; 20,833. Tidak menggunakan display sebagai operand total. Jangan menambahkan mapping JF fiktif untuk koefisien 25; bila dasar mapping resmi belum terbukti, test hanya fixture numerik, bukan bukti production resolution.

### Fixture ownership untuk Phase 02C.5.2.A

TC-PAK-001/TC-PAK-037 tetap numeric Golden Nurmaulida: Nov–Dec 2023 = `25/6`, 2024 = `25/1`, 2025 = `25/1`, Jan–Oct 2026 = `125/6`, complete exact conversion total `75/1`. Fixture ini membuktikan exact arithmetic, no rounded-line summation, rounding/presentation separation, dan no AK Dasar double counting. Ia tidak membuktikan documentary SK identity, TMT Jabatan SK, real EmployeePositionHistory provenance, OD-04 continuity, OD-13 evidence, atau actual functional-position/functional-level mapping Nurmaulida. Verified mapping tetap pending; jangan retrofit invented SK, position, level, history, atau provenance ke numeric Golden.

02C.5.2.A boleh memakai **fixture preview integration terpisah yang seluruhnya eksplisit synthetic/test data**, bukan factual evidence Nurmaulida atau pegawai nyata mana pun. Fixture ini boleh memuat synthetic person/case, verified/non-superseded histories dengan continuity OD-04, documentary-complete OD-13 fields (termasuk synthetic sk_number, source_ref/documentary reference dan tmt_start yang memenuhi contract), functional position/level mappings, evaluations, rule set/child rules, serta dependency lain yang diperlukan accepted contracts.

Ownership synthetic fixture adalah orchestration integration: OD-03 → source → OD-04 → OD-13 → regime → child rules → calculator → manifest/fingerprint. Fixture boleh sengaja menghasilkan exact lines `25/6`, `25/1`, `25/1`, `125/6` dan total `75/1`. Kesamaan angka hanya integration arithmetic expectation; tidak menjadikannya Nurmaulida evidence atau bukti real-world documentary provenance. Original Nurmaulida Golden tetap numeric business regression. Jangan melemahkan OD-13 agar numeric-only Golden dapat dipaksakan melalui preview. Klarifikasi ownership ini bukan business rule baru dan bukan klaim executable preview tests sudah tersedia.

## TC-PAK-038 — Personnel provenance dan guidance

**Rules:** BR-DATA-001, BR-REF-001. Requirement dokumen resmi mencakup KARPEG, tempat/tanggal lahir, TMT pangkat/gol, dan TMT jabatan -> SK dengan provenance. Default unit/instansi harus berlabel default client, tidak otomatis menjadi bukti historis. Tabel perpindahan/target KP/KJ yang tersedia sebagai guidance tidak mengaktifkan calculator fase berikutnya atau mengubah hasil Phase 02. Checkpoint ini tidak mengubah importer, schema, atau UI executable.

UAT Phase 02 wajib mencakup TC-PAK-029–038 dan TC-PAK-006. Kasus lintas fase di matriks V1 tidak dianggap selesai hanya karena konversi Phase 02 lulus.

## TC-PAK-039 — Requested range full-month dan partial review

**Contract:** OD-03 RESOLVED pada [Phase 02C plan](plans/phase-02c-resolution-conversion-runs.md#od-03--resolved-partial-requested-range); BR-SKP-003 dan boundary BR-JAB-001 tetap dipertahankan. Planned acceptance 02C.2, bukan executable test atau klaim sudah diimplementasikan. Seluruh valid-range fixtures berikut diasumsikan contained dalam authoritative PakCase period.

| Requested start .. end | Expected boundary outcome |
|---|---|
| `2026-01-01 .. 2026-03-31` | Jan, Feb, Mar; count 3 |
| `2026-01-15 .. 2026-03-31` | Partial-start: PARTIAL_MONTH_REQUIRES_REVIEW |
| `2026-01-01 .. 2026-03-15` | Partial-end: PARTIAL_MONTH_REQUIRES_REVIEW |
| `2026-01-15 .. 2026-03-15` | Partial-both: PARTIAL_MONTH_REQUIRES_REVIEW |
| `2026-02-01 .. 2026-02-28` | Februari; count 1 |
| `2024-02-01 .. 2024-02-29` | Februari leap year; count 1 |
| `2024-02-01 .. 2024-02-28` | Partial-end: PARTIAL_MONTH_REQUIRES_REVIEW |
| `2026-02-15 .. 2026-02-15` | PARTIAL_MONTH_REQUIRES_REVIEW; bukan count zero |
| `2025-12-01 .. 2026-02-28` | 3 months; 2 year partitions |
| `2026-02-01 .. 2026-02-29` | Invalid date, bukan partial review |
| `2026-03-31 .. 2026-01-01` | Invalid/reversed range, bukan partial review |

- [ ] Exact case range hanya lolos boundary validation jika requested boundary-nya sendiri full-month. Valid full-month subset yang contained dapat lolos meskipun authoritative PakCase period memiliki boundary parsial; eligibility lainnya tetap diperiksa.
- [ ] Starts-before, ends-after, dan fully outside case ditolak; tidak menghasilkan saved run.
- [ ] Case `2026-01-15 .. 2026-03-15` dengan request `2026-02-01 .. 2026-02-28` dapat lolos boundary validation; case period tidak diubah.
- [ ] Semua partial cases mempertahankan exact original dates; tidak menghasilkan complete canonical counted-month list/count, tidak memakai count zero, dan tidak menghitung hanya full months di tengah sebagai complete conversion.
- [ ] Tidak ada prorata, date rounding, auto-expand, silent dropping, atau corrected range otomatis. Review tidak mengotorisasi partial calculation.
- [ ] TMT posisi tengah/akhir bulan sendiri tidak memicu partial-range issue; formal source coverage yang berbeda dari applied months juga tidak memicunya.
- [ ] Coverage resolution read-only: tidak mengubah PakCase, evaluation, history, atau menulis audit. Perubahan request/case harus eksplisit melalui lifecycle yang sesuai.
- [ ] OD-04 history end/gap dan BR-PERIOD-003 rolling window tidak diasumsikan selesai oleh test ini.

## TC-PAK-040 — Position history continuity-first dan whole-month selection

**Contract:** OD-04 RESOLVED pada [Phase 02C plan](plans/phase-02c-resolution-conversion-runs.md#od-04--resolved-history-endgap-dalam-bulan), klarifikasi BR-JAB-001 APPROVED. Planned acceptance 02C.3; bukan executable test atau klaim implementasi. Histories berikut milik canonical person yang sama, verified/non-superseded kecuali dinyatakan lain. Start/end inclusive; null end open-ended. Requested range sendiri full-month sesuai OD-03.

| Fixture / scenario | Expected |
|---|---|
| Satu verified open-ended history mulai sebelum/pada awal bulan | Seluruh bulan assigned ke history tersebut |
| A end `2026-10-14`; B start `2026-10-15`, coverage sampai akhir bulan | Contiguous; B untuk seluruh Oktober |
| Beberapa TMT contiguous dalam Oktober, masing-masing predecessor end sehari sebelum successor | Latest applicable TMT untuk seluruh Oktober; tanpa prorata/split coefficient |
| TMT B tanggal 1, 15, atau 31 Oktober, dengan coverage penuh contiguous | B untuk seluruh Oktober pada ketiga fixture |
| A end `2026-09-30`, tanpa successor Oktober | MISSING_POSITION_HISTORY; full-month gap 1–31 Oktober |
| A end `2026-10-10`, tanpa successor | MISSING_POSITION_HISTORY; gap 11–31 Oktober (contoh B) |
| A end `2026-10-10`; B start `2026-10-20` | MISSING_POSITION_HISTORY; gap 11–19 Oktober (contoh A) |
| A end `2026-09-30`; B start `2026-10-15` | MISSING_POSITION_HISTORY; gap 1–14 Oktober (contoh C) |
| Successor mulai sehari setelah inclusive end | Contiguous jika remainder coverage bulan juga penuh |
| Successor mulai pada tanggal inclusive end yang sama | AMBIGUOUS_POSITION_HISTORY; overlap satu hari |
| A end `2026-10-20`; B start `2026-10-15` | AMBIGUOUS_POSITION_HISTORY; overlap 15–20 Oktober (contoh D) |
| A open-ended; B mulai kemudian tanpa closure A | AMBIGUOUS_POSITION_HISTORY untuk overlap; A tidak dipotong implicit |
| Dua authoritative histories dengan equal TMT conflicting | AMBIGUOUS_POSITION_HISTORY; tanpa arbitrary winner |
| History superseded atau unverified | Diabaikan; tidak menutup gap; jika tidak ada eligible coverage, MISSING_POSITION_HISTORY |
| Tidak ada eligible history | MISSING_POSITION_HISTORY; tanpa zero/synthetic assignment |
| History baru mulai November, Oktober tidak covered | MISSING_POSITION_HISTORY untuk Oktober; November tidak diterapkan mundur |

- [ ] Gap awal/tengah/akhir bulan dan overlap tidak menghasilkan authoritative monthly assignment untuk bulan terdampak.
- [ ] Tidak memperpanjang ended history, membuat synthetic history, menutup gap retroaktif, atau mengisi position/predicate/value zero.
- [ ] Gap issue membawa affected month/uncovered interval dan safe reference; overlap issue membawa affected month/conflicting interval/history IDs bila tersedia.
- [ ] Reverse insertion/query order tidak mengubah outcome; tidak memakai ID, created_at, import order, atau arrival order sebagai tie-break.
- [ ] Documentary gap memakai MISSING_POSITION_HISTORY, bukan PARTIAL_MONTH_REQUIRES_REVIEW atau SOURCE_POSITION_LINK_REQUIRES_REVIEW. TMT tengah/akhir bulan sendiri tidak memicu partial requested-range issue.
- [ ] Resolution read-only/deterministic: original TMT/end/provenance tidak berubah; tidak menulis history, evaluation, PakCase, audit, atau conversion run.

## TC-PAK-041 — Conversion run schema, immutable persistence dan assessment boundary

**Contract:** OD-09 RESOLVED dan OD-10 RESOLVED for schema/auth/audit mutation boundary pada [Phase 02C plan](plans/phase-02c-resolution-conversion-runs.md#9-02c4--conversion-run-schema--persistence). TC-PAK-041 adalah umbrella persistence acceptance contract lintas 02C.4–02C.6. Acceptance 02C.4 hanya membutuhkan structural/schema/immutable-persistence requirements berlabel `[02C.4]`. Orchestration, mutation authorization/audit dan review lifecycle menjadi executable gate `[02C.5]`; replacement runtime behavior/concurrency menjadi executable gate `[02C.6]`. Planned items slice berikutnya yang belum executable tidak memblok acceptance 02C.4. Checklist ini bukan executable tests atau klaim fitur sudah tersedia.

- [ ] [02C.4] Tepat runs + periods schema; tanpa third monthly-assignment/segment table. FK RESTRICT, upgrade/rollback dependency order, SQLite dan disposable MariaDB verification sesuai compatibility gate.
- [ ] [02C.4] Unique case/revision, nullable predecessor, nullable active_case, dan run/line menolak collision; multiple historical NULL projections tetap valid.
- [ ] [02C.4] PREVIEW/REQUIRES_REVIEW wajib active_case_id = pak_case_id; SUPERSEDED wajib NULL. Structural/model guards menolak inconsistent status/case/projection, cross-person/source/rule ownership, self-reference dan invalid predecessor/sequence.
- [ ] [02C.5] Locked case N dengan successful atomic save menghasilkan case N+1 dan run.case_lock_version N+1. Expected pre-save N tidak disimpan sebagai authoritative run version. Failure tidak meninggalkan run/period/audit/version parsial.
- [ ] [02C.4] Period contiguous full-month, month_count integer positif sesuai interval, deterministic line_number; tidak double-cover bulan. Period bukan salinan otomatis position segment.
- [ ] [02C.4] Exact numerator/denominator canonical integer strings, simplified, numerator nonnegative, denominator positif; tolak exponent/float/negative/zero denominator dan half-null pair tanpa native-int coercion atau pembulatan.
- [ ] [02C.4] Tidak menyimpan rounded machine/human output sebagai authoritative values; simpan profile/version untuk recomputation. Golden persistence fixture exact lines 25/6, 25, 25, 125/6 dan exact total 75 round-trip tanpa AK Dasar/Integrasi/cumulative 175.
- [ ] [02C.5] Orchestration menghasilkan golden exact lines dan total 75 tersebut dari exact calculation, lalu merecompute presentation dari profile/version; tidak menjumlahkan rounded line values.
- [ ] [02C.4] Persistence invariants mempertahankan incomplete run total NULL, bukan zero/subtotal; partial OD-03 tidak direpresentasikan sebagai fabricated periods atau complete count/list dari interior months.
- [ ] [02C.5] Orchestration partial OD-03 mempertahankan tanggal asli dan tidak membuat fabricated periods, normalized range, atau complete count/list dari interior months.
- [ ] [02C.4] Diagnostic lines boleh mempunyai NULL source/history/rule references dan snapshots. rule_snapshot NULL jika failure sebelum rule resolution, bukan `{}`/synthetic rule. Calculated lines wajib applicable snapshots/operands/exact results.
- [ ] [02C.4] Source-history proof berbeda reference dari selected monthly position history; tidak dipaksa sama. Snapshot tetap bermakna setelah live source/history superseded.
- [ ] [02C.4] Manifest v1 menyimpan delapan approved groups, canonical monthly assignments/proof, supporting histories dan relevant source candidate-set evidence termasuk Annual absence/presence. Tidak serialize whole Eloquent models.
- [ ] [02C.4] Satu canonical calculation contract version awal 1, snapshot schema version dan existing rounding profile/version; tanpa lima algorithm columns. Fingerprints bukan replay/idempotency decision.
- [ ] [02C.4] Model boundary menjaga snapshot/context/operands/results/original issues/trace/predecessor identity immutable dan no delete; hanya lifecycle metadata yang diizinkan dapat berubah. Authorization lifecycle mutation diuji pada slice service.
- [ ] [02C.5] PakCase first-use tetap authoritative; preview tidak menandai; save tidak silently repair marker historis.
- [ ] [02C.5] Inactive actor atau missing required permission ditolak sebelum mutation. View/save/recalc/assessment/evidence/audit mengikuti approved permission bundles dan record boundary; no new permission/role.
- [ ] [02C.6] Actual replacement menguji authorization mutation dan record boundary yang sama, termasuk inactive actor/missing permission rejection sebelum perubahan state.
- [ ] [02C.5] Ordinary preview/stale assessment read-only tanpa row/audit. Initial save menghasilkan PREVIEW atau REQUIRES_REVIEW sesuai issues dan tepat satu CONVERSION_RUN_SAVED.
- [ ] [02C.5] Explicit assessment memakai review_assessment/review_assessed_by/review_assessed_at; event CONVERSION_RUN_REVIEW_ASSESSED untuk PREVIEW → REQUIRES_REVIEW atau REQUIRES_REVIEW assessment update. Bukan approval/finalization dan tidak mengubah original snapshot.
- [ ] [02C.5] Tolak REQUIRES_REVIEW → PREVIEW in place dan mutation keluar dari terminal SUPERSEDED. Annual/correction tidak otomatis mengubah status/angka atau membuat successor.
- [ ] [02C.4] Schema/model mendukung replacement melalui unique direct successor, immutable original predecessor identity dan supersession metadata; tidak memerlukan replacement service/runtime pada acceptance 02C.4.
- [ ] [02C.6] Actual replacement membuat old run SUPERSEDED dan new run PREVIEW/REQUIRES_REVIEW secara atomik. Event CONVERSION_RUN_REPLACED subject successor, predecessor via supersedes_id; tanpa duplicate SAVED atau period event. Runtime/concurrency proof mengikuti gate 02C.6.
- [ ] [02C.5] Audit failure rollback save/assessment mutation. Payload hanya before/after/reason/supersedes_id dengan bounded safe identifiers/status/revision/issue-code summaries; tanpa manifest/full snapshots/NIP/document bodies/private paths/SQL/error traces.
- [ ] [02C.6] Audit failure rollback replacement mutation; event replacement memenuhi payload/privacy boundary yang sama.

OD-07 business replacement policy tetap BUSINESS PENDING dan OD-08 replay/idempotency tetap DEFERRED. OD-11 kini RESOLVED dengan executable acceptance 02C.5 pada TC-PAK-042; bukan requirement retroaktif acceptance 02C.4. Umbrella TC-PAK-041 tidak mewajibkan runtime 02C.5/02C.6 pada acceptance 02C.4.

## TC-PAK-042 — OD-11 explicit rule validity dan numeric orchestration

**Ownership: Phase 02C.5.** Planned acceptance berdasarkan OD-11 RESOLVED, BR-AUDIT-001, dan section 11 Phase 02C plan. Ini dokumentasi acceptance, bukan executable test atau klaim implementasi tersedia; tidak menambah gate 02C.4.

- [ ] [02C.5] Exact inclusive boundaries dan requested full-month subset yang wholly inside validity lolos validity gate; dependency/eligibility lain tetap diperiksa.
- [ ] [02C.5] Request sebelum validity, setelah validity, crossing start, crossing end, atau crossing keduanya → `RULE_VALIDITY_REQUIRES_REVIEW` / `OUTSIDE_VALIDITY` untuk seluruh request. Boundary validity di tengah bulan tidak dinormalisasi; bila request keluar boundary, seluruh request tanpa numeric calculation.
- [ ] [02C.5] `valid_from` NULL, `valid_until` NULL, dan keduanya NULL → `UNKNOWN_BOUNDARY`, bukan open-ended. Dates asli dipertahankan, results/total NULL, bukan zero/subtotal.
- [ ] [02C.5] Precedence teruji: case/explicit identity → rule existence/status/integrity → range/containment dan OD-03 → validity → child resolution → numeric calculation. Validity gagal tidak memanggil child numeric resolution atau menghasilkan unrelated missing-child issues.
- [ ] [02C.5] Full-month validity failure mempertahankan canonical coverage; diagnostic periods mengikuti existing 02C.4 period-building contract tanpa cardinality baru. OD-03 partial request tidak menghasilkan fabricated months/count/periods; OD-11 tidak menimpanya.
- [ ] [02C.5] DRAFT unused/used tetap resolvable, tanpa status ACTIVE/PUBLISHED baru atau perubahan first-use marker oleh preview. Used version dengan NULL validity menghasilkan review tanpa unlock; remediation melalui explicit new/copy version dan authorized case lifecycle bila tersedia/approved.
- [ ] [02C.5] Setelah validity lolos, missing exact predicate → `PREDICATE_RULE_NOT_FOUND`; missing exact coefficient untuk canonical level → `COEFFICIENT_RULE_NOT_FOUND`. Affected line diagnostic, complete total NULL. Valid lines tidak dijadikan subtotal total authoritative.
- [ ] [02C.5] Position resolution sukses tanpa canonical functional_level_id → `MISSING_FUNCTIONAL_LEVEL`, tanpa ID/level/label substitution. Gap/overlap tetap `MISSING_POSITION_HISTORY`/`AMBIGUOUS_POSITION_HISTORY`, bukan missing-level issue.
- [ ] [02C.5] Duplicate/conflicting child rules, corrupt validity, dan ownership mismatch → `RULE_INTEGRITY_ERROR`; unsupported/corrupt status → `RULE_SET_NOT_USABLE`; missing rule set → `RULE_SET_NOT_FOUND`; invalid resolver input → `INVALID_RULE_INPUT`. Tidak ada arbitrary winner atau authoritative contradictory save.
- [ ] [02C.5] Empat review-saveable outcomes OD-11 dapat menghasilkan diagnostic REQUIRES_REVIEW hanya bila semua save gates sukses; integrity/rejection outcomes tidak dipersist sebagai authoritative run. Preview boleh memuat safe diagnostics bila sesuai.
- [ ] [02C.5] Inactive historical FunctionalLevel tetap resolvable; tidak mengarang inactive lifecycle untuk child predicate/coefficient.
- [ ] [02C.5] Diagnostic save tetap lock/reload/revalidate dan membandingkan dependency fingerprint; mismatch ditolak untuk re-preview. Ordinary preview tetap read-only tanpa run/audit/first-use mutation.
- [ ] [02C.5] Historical saved snapshot, exact results, dan original issues tetap sama setelah dependency change; tidak ada automatic rewrite atau successor.
- [ ] [02C.5] Tidak ada latest/date-based rule-set fallback, automatic split antarversi, silent month drop, valid-interior-month calculation saat global failure, fabricated zero, atau subtotal total.
- [ ] [02C.5] Issue context bounded: requested/validity dates dan rule-set ID/version; tanpa document bodies, NIP, private paths, raw SQL, exception traces, atau whole-model serialization.
- [ ] [02C.5] OD-07 tetap BUSINESS PENDING dan OD-08 tetap DEFERRED; test tidak mengunci replacement/replay business behavior yang belum disetujui.

## Klarifikasi teknis Foundation

Uji whitelist DUK published tanpa izin SDM; histori verified tanpa tanggal fiktif; organisasi historis bertanggal; nullable validity DRAFT; pasangan tanggal valid; overlap rule diperbolehkan; purpose_type terpisah dari mekanisme/hasil; retensi akun/audit. Integrasi kebutuhan berikutnya memakai published batch/version Peta Jabatan, bukan bulan DUK.


## Corrective Phase 01 — Internal Workspace

Test matrix lengkap dan kontrak keamanan: [internal-workspaces.md](internal-workspaces.md). Automated coverage: tests/Feature/Pak/InternalWorkspaceTest.php dan PanelAuthorizationTest.php. Mencakup permission/role campuran, 0/1/>1 landing, intended URL/record authorization, selector, navigation route/component, dua workspace Livewire, permission revocation, akun inactive, seeder idempotent, serta seluruh /pak/* lama 404. Tidak menambah business rule Phase 02.


## UX Polish Phase 01 — acceptance regression

Coverage: tests/Feature/Pak/PresentationPolishTest.php dan InternalWorkspaceTest.php.

- Selector Modul Aplikasi: label Manajemen SDM/PAK Konversi, CTA Buka Modul, icon, satu tautan per card, menu akun, tanpa sidebar; permission dan landing tetap.
- Navigasi sesuai kelompok/urutan final, satu home link, akses single/multi-module tetap.
- Data Pegawai menghitung query daftar existing termasuk Person tanpa profil verified; keterangan ringkas: Data pada daftar PAK; bukan ukuran verifikasi atau kelengkapan.
- Count berizin kosong = 0; tanpa izin = — dan tidak mengeksekusi count. Perlu Pemeriksaan dan PAK Ditetapkan selalu placeholder Phase 01.
- Aktivitas: search actor/kode/objek, filter jenis/tanggal dan kombinasi, rentang satu sisi, invalid range, empty result, akhir hari inclusive, timezone konfigurasi termasuk selain Asia/Jakarta.
- Detail escaped dan read-only, kode tidak dikenal fallback, tidak menulis event saat melihat/filter.
- Badge semantic color per role/multi-role; status action danger/success, alasan wajib serta audit deactivate/reactivate tetap.
- Seluruh regression Phase 01/SDM tetap dijalankan; tidak mengimplementasikan test bisnis fase berikutnya sebagai fitur baru.


Revisi referensi portal: test selector memeriksa identitas/selamat datang/deskripsi/footer baru; dashboard memeriksa subjudul dan catatan draf. ActivityDetailPresentationTest memeriksa deactivate/reactivate berdasarkan payload, alasan/narasi, nilai missing/null, payload bertingkat, fallback event tidak dikenal, escaping, dan raw JSON hanya dalam disclosure tertutup. Rendering detail tidak mengubah payload/event.
