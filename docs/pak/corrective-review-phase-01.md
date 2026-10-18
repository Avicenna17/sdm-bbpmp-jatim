> **Historical / superseded (internal panel only):** Desain dua panel /admin + /pak, PakPanelProvider, dan permission Super Admin sebelum corrective workspace di dokumen ini tidak lagi berlaku. Kontrak aktif: [Internal Module Workspace Architecture](internal-workspaces.md). Hasil test lama tetap dipertahankan sebagai histori; schema/domain dan pending business rules tidak berubah.

# Corrective review Phase 01

Scope hanya empat temuan review; tidak menambah fungsi Phase 02. Tidak commit, push, deploy atau migrate database aplikasi. Git index/staging tidak diubah; phase-01-review.patch yang sudah ada juga tidak diubah.

## Koreksi

1. Tambah nullable first_used_at pada migration Phase 01 create_pak_rule_sets sesuai instruksi (bukan migration production baru). PakCaseService menetapkan marker di bawah row lock dalam transaksi case; preview tidak menandainya. Kegagalan transaksi membatalkan marker. Model, service, dan policy menolak edit setelah pernah dipakai, termasuk setelah case berpindah ke rule lain. Model juga menolak pengosongan/penggantian marker dan mutation memakai instance stale.
2. Create/refresh/preview dengan pak_rule_set_id memerlukan pak.rule-set.view pada service sebelum validasi ID, tidak hanya dropdown.
3. Mapping aktif memerlukan FunctionalPosition, FunctionalLevel, FunctionalCategory aktif, dengan row lock saat validasi. Mapping nonaktif boleh dibuat/disimpan untuk retensi.
4. Pencarian DUK memakai personnel_snapshots.name_at_period.

## File koreksi

- database/migrations/2026_10_17_000800_create_pak_rule_sets.php
- app/Models/PakRuleSet.php
- app/Policies/Pak/PakRuleSetPolicy.php
- app/Domain/Pak/Services/PakRuleSetService.php
- app/Domain/Pak/Services/PakCaseService.php
- app/Domain/Pak/Services/FunctionalMasterService.php
- app/Domain/Pak/Queries/EmployeeReferenceQuery.php
- tests/Feature/Pak/CorrectiveReviewTest.php (baru)
- docs/pak/database-pak.md
- docs/pak/foundation.md
- docs/pak/corrective-review-phase-01.md (laporan ini)

## Verifikasi

Phase 01: 37 passed, 0 skipped, 0 failed, 235 assertions.
Suite gabungan final: **112 tests = 109 passed, 3 skipped, 0 failed/error; 819 assertions**, 03:43.892. PHPUnit 11.5.56/PHP 8.2.25, SQLite in-memory.

| Suite | Passed | Skipped | Failed/error | Assertions |
|---|---:|---:|---:|---:|
| Baseline existing | 72 | 3 | 0 | 584 |
| Existing sesudah koreksi | 72 | 3 | 0 | 584 |
| Phase 01 sesudah koreksi | 37 | 0 | 0 | 235 |
| Gabungan final | 109 | 3 | 0 | 819 |

Dibanding baseline: +37 passed, +235 assertions, skip/failure unchanged. Dibanding implementasi sebelum review: +7 passed dan +43 assertions. Tidak ada regression failure.

Commands:
- php vendor/bin/phpunit tests/Feature/Pak --do-not-cache-result
- php vendor/bin/phpunit --do-not-cache-result --display-skipped --log-junit storage/logs/pak-phase-01-corrective.xml
- php vendor/bin/pint --test (seluruh PHP Phase 01 yang berbeda dari HEAD dan test corrective baru): passed.
- git diff --check dan git diff --cached --check: passed.

Skip existing:
1. ActualDukWorkbookTest::test_september_workbook_preview_and_commit_in_isolated_database — SDM_DUK_WORKBOOK belum tersedia.
2. ImportTemplateTest::test_user_testing_workbooks_when_available — workbook lokal opsional belum tersedia.
3. PositionDocumentationTest::test_actual_position_workbook_retains_all_24_columns — SDM_POSITION_WORKBOOK belum tersedia.

Tidak ada pre-existing failure atau failure pada corrective runs.

Tujuh regression test/case baru: R1 ke R2 dan permanent immutability; rollback first_used_at; service authorization create/refresh/preview; parent JF inactive; parent jenjang inactive; parent kategori inactive; qualified DUK search. Tes mapping mencakup create active ditolak, inactive dipertahankan, reactivation ditolak/diterima sesuai parent, dan deactivation untuk retensi.

Baseline existing: 72 passed, 3 skipped, 0 failed, 584 assertions.
Sebelum corrective review: 102 passed, 3 skipped, 0 failed, 776 assertions.

PENDING business decisions tetap sama. MySQL staging/concurrency dan UAT tetap belum diklaim terverifikasi. Jika migration Phase 01 telah diterapkan pada database lokal, perubahan file create migration tidak otomatis menambah kolom pada database itu; jangan memakai aplikasi terhadap schema lama. Untuk DB uji disposable gunakan fresh migration; database berisi data harus ditangani melalui prosedur yang direview tanpa penghapusan data.
