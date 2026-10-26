# Keputusan teknis Phase 01

Bagian Phase 01 berikut merupakan histori keputusan saat foundation diterapkan; pada saat itu tidak ada business rule PENDING yang diubah menjadi keputusan final. Pembaruan client Phase 02 dicatat terpisah di bawah.

1. Koreksi Peta Jabatan diterapkan langsung pada database-pak.md dan klarifikasi spesifikasi terkait: published version berasal dari position_datasets/import_batches, independen dari bulan reporting_periods DUK.
2. UI histori menggunakan action modal pada EmployeeResource + viewer read-only, bukan relation-manager terpisah. Service/authorization/retensi identik dengan plan; tidak ada CRUD Person global.
3. Master identity (code/category/pasangan mapping) tidak diubah lewat edit, termasuk sebelum terpakai; buat master/mapping baru. Label dan is_active tetap dapat diedit. Ini menghindari repurposing ID dan menjaga label snapshot.
4. Tanggal kontrak case/histori/rule/organisasi diterima dalam format ISO YYYY-MM-DD. whereDate digunakan untuk rentang inklusif agar hasil konsisten pada DATE MySQL dan serialisasi date Eloquent di SQLite.
5. Bukti organisasi tanpa akhir hanya dianggap point-in-time. Operator dapat memasukkan rentang nyata dari bukti, bukan tanggal perkiraan; data tanpa bukti tetap null.
6. Retensi actor menggunakan RESTRICT + deaktivasi akun. Authorization Person terisolasi pada PakEmployeeAuthorization/resource/service, tanpa global PersonPolicy.
7. Factory Person disediakan; fixture PAK menggunakan helper sintetis PakTestCase dan service domain agar test tidak melewati validasi/audit.
8. No database ENUM/CHECK daftar status. Rule DRAFT nullable validity; tujuan case memakai purpose_type, sesuai tiga koreksi final.
9. Database/schema aplikasi tidak dimigrasikan saat implementasi. Fresh/upgrade/constraint diuji di SQLite in-memory; MySQL concurrency dan UAT dicatat terpisah sebagai verifikasi penerapan.

# Keputusan client Phase 02 — 23 Oktober 2026

**Sumber keputusan:** konfirmasi langsung client yang disampaikan pengguna dalam checkpoint dokumentasi ini. APPROVED di sini adalah keputusan bisnis client; bukan klaim audit regulasi baru. Jika kemudian ditemukan konflik regulasi resmi, ikuti prioritas sumber dan laporkan sebelum implementasi.

**Scope perubahan:** hanya `plans/phase-02-skp-conversion.md`, `business-rules.md`, `test-cases.md`, dan `decisions.md`, pada branch `feature/pak-phase-02`. Tidak membuat source, migration, model, service, UI, calculator, seeder, dependency, atau test executable. Tidak commit/push.

| Keputusan | Aturan | Dampak terhadap plan sebelumnya |
|---|---|---|
| Annual tersedia menjadi sumber utama tahun terkait, termasuk subset bulan | BR-SKP-002 | Annual subset tidak lagi pending dan tidak otomatis memunculkan review issue |
| Tanpa annual gunakan triwulan terakhir relevan; Jan–Okt memakai Q3 bila Q4/annual belum tersedia | BR-SKP-009, BR-SKP-006 | Tidak membuat provisional terpisah untuk Oktober; coverage asli sumber dan applied range tetap berbeda |
| Satu bulan hanya satu sumber predikat dalam satu kalkulasi, termasuk preview | BR-SKP-004/005 | Record sumber boleh coexist, pilihan kontribusi bulan tidak overlap |
| Annual majemuk akibat perubahan jabatan memakai record terkait jabatan terbaru | BR-SKP-010 | Bukan pemilihan berdasarkan waktu upload/ID; hubungan ke histori harus dapat dibuktikan |
| Jabatan/jenjang dengan TMT terbesar yang berlaku per bulan menjadi dasar koefisien | BR-JAB-001 | Beberapa perubahan dalam bulan sama memakai TMT paling akhir; bukan satu jenjang akhir untuk seluruh rentang |
| TMT tengah/akhir bulan tetap satu bulan penuh dengan jenjang terbaru | BR-JAB-001 | Tidak ada prorata hari atau partial-month blocker hanya karena TMT; tanggal histori asli tetap |
| TMT Jabatan < 2023-01-01 = LEGACY / AK Integrasi; TMT Jabatan >= 2023-01-01 = PERFORMANCE CONVERSION / konversi predikat kinerja | BR-REGIME-001 | AK Integrasi nilai existing, tidak dihitung ulang; jangan membuat calculator aturan lama; rule/version tetap extensible |
| Personnel source tambahan dan default organisasi | BR-DATA-001 | Requirement/provenance saja, tanpa perubahan importer; default bukan bukti historis |
| Materi resmi/PPT untuk guidance Admin PAK | BR-REF-001 | Pisahkan engine Phase 02, guidance, dan rule phase berikutnya; tabel tidak otomatis calculator aktif |
| Presisi penuh, total-only ROUND_HALF_UP maksimal 3 desimal, trim trailing zeros | BR-KONV-004 | Rounding tidak lagi pending; PREVIEW_ONLY tetap batas lifecycle, bukan ketidakpastian rounding |
| Nurmaulida numeric fixture tanpa mapping JF fiktif | TC-PAK-001/037 | Hasil numerik 75 tidak membuktikan resolusi jabatan produksi |
| Koreksi client golden Nurmaulida berdasarkan dokumen PAK terbaru | TC-PAK-001 | Konversi baru 75; AK Dasar 100; akumulasi dan PAK kumulatif 175. Penetapan: AK Dasar Lama 100, AK Konversi Lama tidak ada/0, AK Konversi Baru 75; kumulatif Lama 100 + Baru 75 = 175. AK Dasar tidak dicatat kembali sebagai AK Konversi Lama dan tetap berbeda dari AK Integrasi/legacy. Akumulasi/penetapan tetap phase berikutnya; scope dan schema SKP/source 02A.1 tidak berubah. |

Default Unit Kerja: **Balai Besar Penjaminan Mutu Pendidikan Provinsi Jawa Timur**. Default Instansi: **Kementerian Pendidikan Dasar dan Menengah**. Requirement personnel: KARPEG/Nomor Seri Karpeg, TEMPAT LAHIR, TGL LAHIR, TMT PANGKAT/GOL, dan TMT JABATAN -> SK. Wajib provenance sumber dan verifikasi; tidak mengasumsikan current DUK sebagai bukti historis tanpa verifikasi.

Sumber SKP sekarang di-resolve sesuai prioritas bisnis; operator meninjau hasil tanpa override ke sumber yang lebih rendah prioritas. Rule-set selection tetap berbeda: satu versi dipilih eksplisit, tidak otomatis berdasarkan tanggal/versi terbaru. Kalkulasi tetap server-authoritative, exact rational; seluruh snapshot, correction chain CURRENT dengan unique successor, replacement chain latest run dengan unique predecessor, audit privacy dan permission baseline dipertahankan.

Semua hasil Phase 02 tetap PREVIEW/REQUIRES_REVIEW/SUPERSEDED. Tidak menambah FINAL/COMPLETED/APPROVED/PAK_ESTABLISHED, akumulasi, penetapan, KP/KJ, atau KGB. Tabel, service architecture, permission, dan checkpoint 02A–02E tetap; test specification disinkronkan melalui TC-PAK-003/004 dan TC-PAK-029–038.

Yang masih pending: BR-SKP-008 (kebijakan refresh/revision calculation/draft triwulan ketika annual tersedia kemudian), BR-PERIOD-003, BR-APPOINT-008, BR-KJ-005, BR-BAL-003 serta BR-BAL-004 yang masih proposed; dasar resmi mapping Nurmaulida, pengesahan nilai master REFERENCE, lintas versi regulasi, dan retensi evidence. Requested range batas hari parsial kemudian diselesaikan melalui OD-03 di bawah. Keputusan TMT untuk perhitungan bulanan tidak menyelesaikan trigger saldo/keputusan administratif BR-BAL-003.

## Klarifikasi final checkpoint dokumentasi

- **RESOLVED:** annual diprioritaskan termasuk subset; tanpa annual gunakan triwulan terakhir relevan (Jan–Okt memakai Q3); tidak ada provisional source terpisah; satu bulan satu sumber predikat.
- **PENDING BR-SKP-008:** annual yang datang setelah calculation/draft triwulan tidak menyebabkan auto-update calculation lama. Bukti sumber saat calculation tetap tersimpan. Kebijakan kapan/bagaimana refresh atau revision belum dikunci; kontrak teknis correction/replacement chain bukan persetujuan kebijakan bisnis ini.
- Pemilihan jabatan/jenjang serta record annual terkait menggunakan TMT terbesar yang berlaku pada bulan tersebut; jika beberapa perubahan dalam bulan sama pilih TMT paling akhir. Tengah/akhir bulan tetap bulan penuh. Jangan memakai urutan record, ID database, tanggal import atau urutan Excel; jenjang bulan berikutnya tidak diterapkan mundur.
- **CALCULATION BR-KONV-004:** simpan/hitung presisi penuh, jangan bulatkan baris, jumlahkan nilai presisi penuh, ROUND_HALF_UP hanya output/total akhir maksimum tiga desimal.
- **DISPLAY:** trim trailing zeros: 100.000 -> 100; 18.750 -> 18,75; 12.500 -> 12,5; 20.833 -> 20,833. Formatting tidak mengubah calculation.
- Konsep/permission provisional pada desain terdahulu dipertahankan sebagai referensi historis/retensi tanpa membuka alur input provisional Phase 02. Arsitektur, tabel, chain, baseline permissions dan checkpoint tidak diubah. Golden Nurmaulida tetap numeric fixture tanpa mapping JF/jenjang rekaan.

# Keputusan client Phase 02C — OD-03 FINAL RESOLVED

**Sumber:** persetujuan eksplisit client atas OD-03 setelah acceptance 02C.1 (`73b87b0`); bukan klaim verifikasi regulasi baru. Contract authoritative: [OD-03 pada Phase 02C plan](plans/phase-02c-resolution-conversion-runs.md#od-03--resolved-partial-requested-range). Tidak membuat business-rule ID baru atau mengubah approval status rule lain.

Requested range wajib valid, non-empty, start <= end, contained dalam PakCase period, mulai tanggal 1 dan berakhir hari terakhir bulan (termasuk leap year) untuk directly calculable pada aspek boundary bulan. Partial start/end mempertahankan exact original dates dan menghasilkan `PARTIAL_MONTH_REQUIRES_REVIEW`; tidak ada prorata, date rounding, auto-expand, silent dropping, partial month = zero, atau complete conversion dari full months di tengah saja.

Review tidak mengotorisasi partial calculation. Diperlukan explicit revised full-month requested range yang tetap contained atau future explicit business decision. Tidak ada implicit case-period mutation/corrected range otomatis; perubahan case period memakai lifecycle case terlebih dahulu. TMT tengah/akhir bulan dan formal source coverage berbeda dari applied months tidak memicu issue ini sendiri. OD-04 dan BR-PERIOD-003 tetap belum diselesaikan oleh OD-03. Acceptance 02C.2 mengacu TC-PAK-039; keputusan ini documentation-only, bukan implementasi resolver.
