# Test Cases
## Sistem PAK Konversi BBPMP Provinsi Jawa Timur

**Versi:** 1.0  
**Tanggal:** 17 Oktober 2026

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

Rounding policy final harus konsisten.

---

# 3. Golden Test — Nurmaulida

## TC-PAK-001 — PAK Nurmaulida Sitoresmi

**Priority:** CRITICAL  
**Source:** dokumen PAK fisik BBPMP Jatim

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
4.167 + 25 + 25 + 20.833
= 75.000
```

### Expected Akumulasi

Dokumen Akumulasi memiliki AK sebelumnya:

```text
AK existing = 100
AK baru     = 75
Total       = 175
```

### Expected PAK

Dokumen Penetapan:

```text
AK Dasar        = 100
AK Konversi     = 175
Total Kumulatif = 275
```

### Assertions

- [ ] Empat conversion line terbentuk.
- [ ] Tidak ada overlap.
- [ ] Total conversion baru = 75.
- [ ] Akumulasi = 175.
- [ ] PAK kumulatif = 275.
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

# 5. Periode Jan–Mei dengan Sumber Berbeda

## TC-PAK-003 — Segmentasi SKP Jan–Mei

### Given

Jenjang:

```text
Ahli Muda
Coefficient = 25
```

SKP:

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
Jan-Mar = 3 bulan
Apr-Mei = 2 bulan
```

### Expected AK

```text
Jan-Mar:
3/12 × 1.00 × 25
= 6.25

Apr-Mei:
2/12 × 1.50 × 25
= 6.25

Total:
12.50
```

Assertions:

- [ ] TW II dipotong hanya Apr-Mei.
- [ ] Juni tidak dihitung.
- [ ] Dua line non-overlap.

---

# 6. Jan–Oktober dengan Data Darurat

## TC-PAK-004 — Provisional Oktober

### Given

```text
Jenjang = Ahli Muda
Coefficient = 25
Jan-Sep official = Baik
Oktober official belum tersedia
SDM input Oktober provisional = Baik
```

### Expected

```text
Jan-Sep:
9/12 × 1 × 25 = 18.75

Okt:
1/12 × 1 × 25 = 2.083333...

Total = 20.833333...
Display = 20.833
```

Assertions:

- [ ] provisional membutuhkan alasan.
- [ ] entered_by tersimpan.
- [ ] source type dapat dibedakan.
- [ ] provisional tetap dapat digunakan untuk draft/final sesuai permission.

---

# 7. Provisional Digantikan Resmi

## TC-PAK-005 — Official Supersedes Provisional

### Given

Provisional Oktober:

```text
Baik
```

Kemudian official TW IV tersedia:

```text
Baik
```

### Expected

- [ ] provisional tidak dihapus;
- [ ] status provisional menjadi `SUPERSEDED`;
- [ ] official memiliki link/reference;
- [ ] draft case dapat direcalculate menggunakan official.

Jika PAK sudah final:

- [ ] tidak auto-overwrite hasil final;
- [ ] flag review/correction muncul.

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

ke final case yang sama.

### Expected

- [ ] validation error overlap;
- [ ] tidak boleh finalize;
- [ ] system menawarkan segment yang belum dihitung bila implementasi mendukung.

---

# 9. AK Integrasi

## TC-PAK-007 — Integrasi + Konversi Jan–Jun

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
| TC-PAK-004 | Provisional | Ya |
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

## Klarifikasi teknis Foundation

Uji whitelist DUK published tanpa izin SDM; histori verified tanpa tanggal fiktif; organisasi historis bertanggal; nullable validity DRAFT; pasangan tanggal valid; overlap rule diperbolehkan; purpose_type terpisah dari mekanisme/hasil; retensi akun/audit. Integrasi kebutuhan berikutnya memakai published batch/version Peta Jabatan, bukan bulan DUK.


## Corrective Phase 01 — Internal Workspace

Test matrix lengkap dan kontrak keamanan: [internal-workspaces.md](internal-workspaces.md). Automated coverage: tests/Feature/Pak/InternalWorkspaceTest.php dan PanelAuthorizationTest.php. Mencakup permission/role campuran, 0/1/>1 landing, intended URL/record authorization, selector, navigation route/component, dua workspace Livewire, permission revocation, akun inactive, seeder idempotent, serta seluruh /pak/* lama 404. Tidak menambah business rule Phase 02.
