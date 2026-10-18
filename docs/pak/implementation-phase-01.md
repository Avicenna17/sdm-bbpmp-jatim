> **Historical / superseded (internal panel only):** Desain dua panel /admin + /pak, PakPanelProvider, dan permission Super Admin sebelum corrective workspace di dokumen ini tidak lagi berlaku. Kontrak aktif: [Internal Module Workspace Architecture](internal-workspaces.md). Hasil test lama tetap dipertahankan sebagai histori; schema/domain dan pending business rules tidak berubah.

# Laporan Implementasi Phase 01 — PAK Foundation

Branch: feature/pak-phase-01. Baseline aplikasi: 51130e3 / pre-pak-phase-01. HEAD sebelum implementasi: f95ab26 (penambahan spesifikasi/plan). Belum commit final, push, merge, deploy, maupun migrate database aplikasi.

## Scope hasil

Hanya foundation sesuai plan approved. Tidak dibuat SKP, calculator, konversi, AK Integrasi, ledger, akumulasi, penetapan, eligibility KP/KJ, Ukom, balance/reset, portal, pengajuan, warning engine, ataupun generator dokumen. Nilai KP/KJ pada purpose hanya label tujuan pemeriksaan.

## Database dan migration

11 file migration additive; tidak ada migration existing yang diubah:

- database/migrations/2026_10_17_000100_add_person_id_to_users.php
- database/migrations/2026_10_17_000200_add_is_active_to_users.php
- database/migrations/2026_10_17_000300_create_employee_profiles.php
- database/migrations/2026_10_17_000400_create_functional_categories_and_levels.php
- database/migrations/2026_10_17_000500_create_functional_positions_and_position_levels.php
- database/migrations/2026_10_17_000600_create_employee_rank_histories.php
- database/migrations/2026_10_17_000700_create_employee_position_histories.php
- database/migrations/2026_10_17_000800_create_pak_rule_sets.php
- database/migrations/2026_10_17_000900_create_pak_cases.php
- database/migrations/2026_10_17_001000_create_pak_case_employee_snapshots.php
- database/migrations/2026_10_17_001100_create_pak_events.php

Sebelas tabel: employee_profiles; employee_rank_histories; employee_position_histories; functional_categories; functional_levels; functional_positions; functional_position_levels; pak_rule_sets; pak_cases; pak_case_employee_snapshots; pak_events. Dua migration lain menambah users.person_id dan users.is_active.

Semua status/type menggunakan string/VARCHAR dan validasi aplikasi. Rule dates nullable, pasangan tanggal wajib berurutan. Actor/person/master/case terpakai menggunakan FK RESTRICT. Fresh migration dan upgrade fixture SDM terisolasi diuji. Rollback hanya untuk DB kosong/uji; DB berisi membutuhkan restore atau forward fix. MySQL staging/concurrency belum diuji, jangan menganggap SQLite membuktikan perilaku locking MySQL.

## Model, relationship dan kontrak data

- User belongsTo Person; Person hasOne User/Profile, hasMany RankHistory/PositionHistory/PakCase. person_id nullable + unique, tidak dibuka untuk mass assignment umum.
- Histori belongsTo Person/verifier dan self-reference supersedes. Jabatan utama belongsTo JF/jenjang; main-history non-overlap inklusif. Koreksi menyimpan row lama; tanpa TMT nyata tidak ada histori verified.
- FunctionalCategory hasMany FunctionalLevel; FunctionalPosition memiliki level melalui mapping; model mapping berelasi ke keduanya. Delapan jenjang/dua kategori di-seed. Tidak mengarang daftar JF/mapping.
- PakRuleSet hasMany PakCase. Code/version unique; hanya metadata DRAFT, validity boleh null/parsial; overlap diperbolehkan. Versi terpakai dikunci; copy ke versi baru tersedia.
- PakCase belongsTo Person/RuleSet, hasOne EmployeeSnapshot, hasMany PakEvent. Draft parsial, rule nullable, purpose_type ROUTINE/KP/KJ/KP_KJ; person immutable di service, lock_version untuk stale edit.
- Snapshot menyalin identitas, profil, histori per tanggal, label JF/jenjang/kategori dan organisasi historis berbukti. ID DUK hanya provenance, bukan FK. Reimport tidak mengubah snapshot case.
- EmployeeProfile organization adalah current/default; tidak mengisi organisasi historis otomatis. Refresh snapshot memerlukan preview, alasan, fingerprint sumber dan versi case.
- Audit append-only/read-only dengan actor/time dan before/after; refresh juga menyimpan perubahan metadata case. Case/snapshot/audit dalam transaksi yang sama. Tidak ada raw workbook dalam audit.

## Permission, role dan perubahan auth

11 permission: pak.access, pak.employee.view, pak.employee.manage, pak.master.view, pak.master.manage, pak.rule-set.view, pak.rule-set.manage, pak.case.view, pak.case.create, pak.case.update, pak.audit.view.

Admin PAK mendapatkan default ketika role pertama dibuat. Pegawai kosong, belum punya portal. Tidak ada role PAK otomatis untuk akun existing, Admin SDM, atau Super Admin. Rerun seeder mempertahankan grant/revoke PAK eksplisit dan status inactive; permission cache direset.

User–Person mapping/unlink/relink melalui action user.manage dengan alasan, locking, uniqueness, audit; case tetap milik people.id yang sama. Deactivate/reactivate melalui action terpisah, mempertahankan role/link/audit. Self-deactivate dan last active managing admin ditolak; remember token dirotasi. Login akun inactive ditolak. Middleware aktif persisten pada panel/Livewire dan route export/template menghentikan request sesi lama; service memeriksa actor aktif.

Tidak ada global PersonPolicy PAK. Policy hanya untuk model PAK; Person authorization memakai resource/service PAK terisolasi. Ownership query disiapkan/dibatasi person tanpa endpoint portal.

## Panel /pak dan referensi DUK

Provider terpisah: dashboard foundation, data pegawai/profil/histori, master taxonomy/JF/mapping, metadata rule set, draft case, audit. Namespace discovery tidak masuk /admin.

/admin tetap memerlukan period.view. /pak memerlukan pak.access dan permission aksi. pak.employee.view mengizinkan query DUK published terbatas tanpa period.view. EmployeeReferenceQuery memfilter person, periode, cutoff tanggal dan published, paginasi 20; DukReferenceData whitelist tidak mengeluarkan raw_payload, extra_data, path/file import, atau field di luar kontrak. Tidak memberikan import/publish/edit/export SDM.

## Pengujian

Baseline: 75 tests = 72 passed + 3 skipped, 0 failed, 584 assertions. Working tree bersih sebelum perubahan. Tidak ada pre-existing test failure.

Phase 01 run terakhir terpisah: 30 passed, 0 skipped, 0 failed, 192 assertions.
Suite gabungan final: **105 tests = 102 passed + 3 skipped, 0 failed/error, 776 assertions**, 03:10.492, peak memory 294 MB. Command: php vendor/bin/phpunit --do-not-cache-result --display-skipped --log-junit storage/logs/pak-phase-01-final.xml.

| Suite | Passed | Skipped | Failed/error | Assertions |
|---|---:|---:|---:|---:|
| Baseline existing sebelum perubahan | 72 | 3 | 0 | 584 |
| Existing setelah implementasi | 72 | 3 | 0 | 584 |
| Phase 01 baru | 30 | 0 | 0 | 192 |
| Gabungan final | 102 | 3 | 0 | 776 |

Selisih terhadap baseline: +30 passed, +192 assertions, skip tetap 3, tidak ada regression failure. Hasil per kelas test dari JUnit final:

| Kelas | Tests | Assertions | Skipped | Failure/error |
|---|---:|---:|---:|---:|
| Tests\Feature\ActualDukWorkbookTest | 1 | 0 | 1 | 0 |
| Tests\Feature\DashboardAvailabilityTest | 1 | 1 | 0 | 0 |
| Tests\Feature\ImportNavigationTest | 2 | 34 | 0 | 0 |
| Tests\Feature\ImportTemplateTest | 19 | 191 | 1 | 0 |
| Tests\Feature\MigrationRecoveryTest | 4 | 13 | 0 | 0 |
| Tests\Feature\MonitoringTest | 34 | 261 | 0 | 0 |
| Tests\Feature\Pak\FoundationIntegrityTest | 7 | 27 | 0 | 0 |
| Tests\Feature\Pak\FoundationSchemaTest | 2 | 14 | 0 | 0 |
| Tests\Feature\Pak\FoundationServicesTest | 12 | 43 | 0 | 0 |
| Tests\Feature\Pak\PanelAuthorizationTest | 8 | 104 | 0 | 0 |
| Tests\Feature\Pak\ReimportCompatibilityTest | 1 | 4 | 0 | 0 |
| Tests\Feature\PositionDocumentationTest | 4 | 49 | 1 | 0 |
| Tests\Feature\PositionMigrationTest | 1 | 5 | 0 | 0 |
| Tests\Feature\WorkbookEdgeTest | 9 | 30 | 0 | 0 |


Test baru:
- FoundationSchemaTest: schema/seeder/access preservation dan upgrade data legacy/default active.
- FoundationServicesTest: draft parsial, whitelist, published-only, TMT, correction/overlap, rule metadata, refresh/stale, rollback atomik, ownership/account retention, organisasi bertanggal, purpose validation.
- FoundationIntegrityTest: salah person/periode/future/deleted DUK, empat purpose, overlap rule metadata, inclusive history/JF inactive mapping, mapping unique/unlink, self/last-admin/token/inactive seed, audit immutability/FK, stale source rollback.
- PanelAuthorizationTest: boundary panel/discovery, read-only audit, denied permission, CRUD modal service, histori manual, preview refresh, login/sesi inactive, mounted Livewire rejection/template.
- ReimportCompatibilityTest: importer dan publish existing, reimport published dengan period.revise, canonical person/case/snapshot tetap utuh.
- PakTestCase: fixture sintetis terisolasi. PersonFactory ditambahkan.

Pemeriksaan tambahan: Pint --test lulus; php -l lulus untuk 101 file PHP. git diff --check tidak menemukan error whitespace. Tidak ada perubahan migration existing.

Kegagalan selama pengembangan (sudah diperbaiki, bukan pre-existing): assertion DATE SQLite vs serialisasi Eloquent; nama error key snapshot pada UI; helper pengujian Filament yang mengasumsikan komponen berhasil dirender setelah HTTP 403. Perbaikan pengujian memakai assertion date semantik dan panggilan Livewire langsung. Query overlap menggunakan whereDate untuk batas tanggal inklusif.

Satu run suite final awal dihentikan karena masih memuat test harness lama; tidak dihitung sebagai hasil final. Error akses bootstrap PHP pada sandbox memerlukan eksekusi test tereskalasi; bukan failure aplikasi. Tidak ada test existing yang dihapus/dilewati untuk meloloskan suite.

### Skip existing yang tetap dilaporkan

1. ActualDukWorkbookTest: workbook opsional SDM_DUK_WORKBOOK tidak disediakan.
2. ImportTemplateTest::test_user_testing_workbooks_when_available: workbook lokal opsional tidak tersedia.
3. PositionDocumentationTest::test_actual_position_workbook_retains_all_24_columns: SDM_POSITION_WORKBOOK tidak disediakan.

Tidak mengklaim golden calculation TC-PAK-001 atau tes engine fase berikutnya lulus; engine tidak dibuat.

## Dokumentasi dan adaptasi teknis

README berisi setup/assignment/retensi. Lima spesifikasi PAK diperjelas, termasuk koreksi langsung bahwa Peta Jabatan published memakai position_datasets/import_batches secara independen dari bulan DUK. database-pak memperjelas nullable validity, nullable rule draft, purpose_type, current/default organization. Dokumen lintas fase tetap memuat desain proposed fase berikutnya, bukan klaim implementasi.

baseline-phase-01.md, foundation.md, decisions.md, dan laporan ini ditambahkan. Plan approved dan dokumentasi SDM lama tidak diubah.

Adaptasi minimal: histori melalui modal action + viewer, bukan relation-manager khusus; identitas master immutable lewat editor (nama/active tetap mutable); tanggal input ISO untuk konsistensi lintas DB; fixture domain memakai service agar audit/validasi diuji. Tidak ada perubahan business rule atau perluasan schema di luar foundation plan.

## Business decision tetap PENDING

BR-SKP-008; BR-PERIOD-001/003; BR-APPOINT-008; BR-KJ-005; BR-BAL-003; BR-KONV-004; proposed BR-BAL-004. Selain itu: mapping canonical JF/angka regulasi/pengesahan, resolver versi dan tanggal rule active/published, koreksi final/dokumen/retensi dokumen, akun pimpinan, rekonsiliasi identity fallback, jabatan simultan/Plt/Plh/rangkap, dan validasi contoh Nurmaulida/perpindahan. Tidak ada asumsi permanen mengenai keputusan tersebut.

## Risiko dan manual verification yang masih diperlukan

- [ ] MySQL 8 staging terisolasi: fresh/upgrade, FK/unique/null dates, rollback DB kosong; dua koneksi concurrent untuk mapping user, histori overlap, penggunaan/edit rule, dan refresh stale.
- [ ] Backup/restore DB dan private storage sebelum penerapan; cocokkan applied migration dan catat jumlah row/permission/pointer publication SDM.
- [ ] UAT browser: role Admin SDM/Admin PAK/view-only, navigasi, form, validasi, tabel/modal, kedua panel, layar kecil.
- [ ] Uji sesi browser/remember cookie nyata pada dua browser: deactivate lalu request /admin, /pak, Livewire, export/template; reaktivasi.
- [ ] Verifikasi assignment PAK eksplisit, mapping identitas berdasarkan bukti, tanpa relink otomatis.
- [ ] Prefill DUK incomplete tidak dianggap verified; SK/TMT nyata dan rentang histori disetujui petugas.
- [ ] Organisasi historis hanya dengan sumber bertanggal; current profile tidak dipakai otomatis.
- [ ] Rule tanpa tanggal diterima; rule terpakai dikunci; snapshot refresh ditinjau before/after dan reason.
- [ ] Import/publish/export/dashboard publik dan Peta Jabatan diuji dengan workbook operasional; isi env workbook opsional untuk menjalankan tiga skipped tests.
- [ ] Review akses SQL/audit retention: model guards bukan proteksi dari administrator database.
- [ ] Review hasil implementasi sebelum commit final. Tidak deploy/push/merge atau lanjut Phase 02.

## Inventory file baru (101 file)

- app/Domain/Pak/Authorization/PakEmployeeAuthorization.php
- app/Domain/Pak/DTOs/CreatePakCaseData.php
- app/Domain/Pak/DTOs/DukReferenceData.php
- app/Domain/Pak/DTOs/EmployeeSnapshotData.php
- app/Domain/Pak/DTOs/HistoricalOrganizationContext.php
- app/Domain/Pak/Enums/EmployeePositionType.php
- app/Domain/Pak/Enums/PakCaseSource.php
- app/Domain/Pak/Enums/PakCaseStatus.php
- app/Domain/Pak/Enums/PakPurposeType.php
- app/Domain/Pak/Enums/PakRuleSetStatus.php
- app/Domain/Pak/Queries/EmployeeHistoryQuery.php
- app/Domain/Pak/Queries/EmployeeReferenceQuery.php
- app/Domain/Pak/Queries/PakCaseQuery.php
- app/Domain/Pak/Services/EmployeeHistoryService.php
- app/Domain/Pak/Services/EmployeeProfileService.php
- app/Domain/Pak/Services/EmployeeSnapshotService.php
- app/Domain/Pak/Services/FunctionalMasterService.php
- app/Domain/Pak/Services/PakAuditService.php
- app/Domain/Pak/Services/PakCaseService.php
- app/Domain/Pak/Services/PakRuleSetService.php
- app/Domain/Pak/Services/UserPersonLinkService.php
- app/Domain/Users/AccountStatusService.php
- app/Filament/Pak/Pages/Dashboard.php
- app/Filament/Pak/Resources/EmployeeResource.php
- app/Filament/Pak/Resources/EmployeeResource/Pages/ListEmployees.php
- app/Filament/Pak/Resources/FunctionalCategoryResource.php
- app/Filament/Pak/Resources/FunctionalCategoryResource/Pages/ManageFunctionalCategory.php
- app/Filament/Pak/Resources/FunctionalLevelResource.php
- app/Filament/Pak/Resources/FunctionalLevelResource/Pages/ManageFunctionalLevel.php
- app/Filament/Pak/Resources/FunctionalPositionLevelResource.php
- app/Filament/Pak/Resources/FunctionalPositionLevelResource/Pages/ManageFunctionalPositionLevel.php
- app/Filament/Pak/Resources/FunctionalPositionResource.php
- app/Filament/Pak/Resources/FunctionalPositionResource/Pages/ManageFunctionalPosition.php
- app/Filament/Pak/Resources/PakCaseResource.php
- app/Filament/Pak/Resources/PakCaseResource/Pages/CreateCase.php
- app/Filament/Pak/Resources/PakCaseResource/Pages/EditCase.php
- app/Filament/Pak/Resources/PakCaseResource/Pages/ListCases.php
- app/Filament/Pak/Resources/PakCaseResource/Pages/ViewCase.php
- app/Filament/Pak/Resources/PakEventResource.php
- app/Filament/Pak/Resources/PakEventResource/Pages/ListEvents.php
- app/Filament/Pak/Resources/PakRuleSetResource.php
- app/Filament/Pak/Resources/PakRuleSetResource/Pages/ManageRuleSets.php
- app/Filament/Pak/Support/DukReferenceFields.php
- app/Filament/Pak/Support/EmployeeForms.php
- app/Filament/Pak/Support/ManageMasterRecords.php
- app/Filament/Pak/Support/MasterResource.php
- app/Http/Middleware/EnsureUserIsActive.php
- app/Models/EmployeePositionHistory.php
- app/Models/EmployeeProfile.php
- app/Models/EmployeeRankHistory.php
- app/Models/FunctionalCategory.php
- app/Models/FunctionalLevel.php
- app/Models/FunctionalPosition.php
- app/Models/FunctionalPositionLevel.php
- app/Models/PakCase.php
- app/Models/PakCaseEmployeeSnapshot.php
- app/Models/PakEvent.php
- app/Models/PakModel.php
- app/Models/PakRuleSet.php
- app/Policies/Pak/EmployeePositionHistoryPolicy.php
- app/Policies/Pak/EmployeeProfilePolicy.php
- app/Policies/Pak/EmployeeRankHistoryPolicy.php
- app/Policies/Pak/FoundationPolicy.php
- app/Policies/Pak/FunctionalCategoryPolicy.php
- app/Policies/Pak/FunctionalLevelPolicy.php
- app/Policies/Pak/FunctionalPositionLevelPolicy.php
- app/Policies/Pak/FunctionalPositionPolicy.php
- app/Policies/Pak/PakCaseEmployeeSnapshotPolicy.php
- app/Policies/Pak/PakCasePolicy.php
- app/Policies/Pak/PakEventPolicy.php
- app/Policies/Pak/PakRuleSetPolicy.php
- app/Providers/Filament/PakPanelProvider.php
- database/factories/PersonFactory.php
- database/migrations/2026_10_17_000100_add_person_id_to_users.php
- database/migrations/2026_10_17_000200_add_is_active_to_users.php
- database/migrations/2026_10_17_000300_create_employee_profiles.php
- database/migrations/2026_10_17_000400_create_functional_categories_and_levels.php
- database/migrations/2026_10_17_000500_create_functional_positions_and_position_levels.php
- database/migrations/2026_10_17_000600_create_employee_rank_histories.php
- database/migrations/2026_10_17_000700_create_employee_position_histories.php
- database/migrations/2026_10_17_000800_create_pak_rule_sets.php
- database/migrations/2026_10_17_000900_create_pak_cases.php
- database/migrations/2026_10_17_001000_create_pak_case_employee_snapshots.php
- database/migrations/2026_10_17_001100_create_pak_events.php
- database/seeders/FunctionalMasterSeeder.php
- database/seeders/PakPermissionSeeder.php
- docs/pak/baseline-phase-01.md
- docs/pak/decisions.md
- docs/pak/foundation.md
- resources/views/filament/pak/audit-detail.blade.php
- resources/views/filament/pak/dashboard.blade.php
- resources/views/filament/pak/duk-reference.blade.php
- resources/views/filament/pak/employee-history.blade.php
- resources/views/filament/pak/snapshot-comparison.blade.php
- tests/Feature/Pak/FoundationIntegrityTest.php
- tests/Feature/Pak/FoundationSchemaTest.php
- tests/Feature/Pak/FoundationServicesTest.php
- tests/Feature/Pak/PakTestCase.php
- tests/Feature/Pak/PanelAuthorizationTest.php
- tests/Feature/Pak/ReimportCompatibilityTest.php
- docs/pak/implementation-phase-01.md (laporan ini)

## Inventory file existing yang diubah (15 file)

- README.md
- app/Filament/Auth/Login.php
- app/Filament/Resources/UserResource.php
- app/Models/Person.php
- app/Models/User.php
- app/Providers/AppServiceProvider.php
- app/Providers/Filament/AdminPanelProvider.php
- bootstrap/providers.php
- database/seeders/DatabaseSeeder.php
- docs/pak/business-rules.md
- docs/pak/database-pak.md
- docs/pak/prd-pak.md
- docs/pak/test-cases.md
- docs/pak/workflow-pak.md
- routes/web.php
