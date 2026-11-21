# Phase 02D.2 — Private Evidence UI: design freeze

**Status: 02D.2 DESIGN FROZEN FOR IMPLEMENTATION — berlaku setelah commit dokumentasi design/freeze ini.**

## 1. Baseline, approval, dan batas checkpoint

Baseline desain: `472e40d`, branch `feature/pak-phase-02`, setelah 02D.3 dan 02D.4 FINAL / FROZEN. Rujukan: [Phase 02D plan](phase-02d-ui-evidence-audit-presentation.md#5-02d2--private-evidence-dan-design-gate), [decision register](../decisions.md), dan [business rules](../business-rules.md).

Checkpoint documentation-only ini mencatat persetujuan eksplisit desain persistence, lifecycle, storage, authorization, dan audit D2. Setelah commit dokumentasi ini, PRE-IMPLEMENTATION DESIGN GATE D2 terpenuhi; satu table/model/migration baru, bounded Livewire config expansion, route/controller download, dan bounded D4 presentation extension disetujui **hanya dalam kontrak berikut**. Dokumen ini tidak menjalankan implementasi tersebut.

Implementasi D2 belum diterima; **D2 bukan FINAL**. Phase 02E belum dimulai. Tidak membuka kembali backend 02C atau lifecycle D3/D4, tidak menambah delivery baru, dan tidak menyelesaikan OD-07 / BR-SKP-008 BUSINESS PENDING, OD-08 DEFERRED, maupun pending business rules lainnya.

## 2. Ownership dan batas domain

Evidence pendukung generik adalah **CASE-OWNED**, dengan parent authoritative `PakCase`. Case adalah konteks administratif stabil lintas replacement run. Evidence tetap pada case yang sama ketika revision konversi diganti; tidak dipindahkan, disalin, atau dihapus.

Evidence bukan milik `PakConversionRun`, `PerformanceEvaluation`, `PakSkpImportBatch`, atau `PakSkpImportRow`. Minimal D2 **tidak memiliki direct maupun automatic evaluation-revision linkage**. Revision evaluasi tidak memindahkan/mengganti/menyalin/menghapus evidence. Evaluation-specific attachment memerlukan keputusan eksplisit berikutnya.

Import workbook provenance tetap terpisah. Jangan menggunakan batch/row, source identity fingerprint, normalized SKP payload, atau import object key sebagai general evidence representation. Akses satu evaluation tidak mengotorisasi download workbook multi-person. `SkpSourceStorage` bukan service generic evidence.

Evidence hanya dokumentasi pendukung, bukan authority untuk predicate factor, coefficient, months, position, functional level, selected SKP source, rule identity, AK line/total, conversion/review status, replacement eligibility, dependency fingerprint, atau snapshot fingerprint. Upload tidak memanggil calculation/assessment/review/replacement atau mengubah evidence perhitungan historis.

## 3. Lifecycle dan retention

Operasi minimal: **upload baru, list metadata, authorized download**. Evidence append-only dan immutable setelah persistence berhasil.

Tidak tersedia edit bytes/metadata, overwrite, replacement, delete/removal, automatic supersession, atau version chain. Upload tambahan membuat record independen; tidak ada hubungan old/new yang diinferensikan, termasuk bila bytes sama.

Upload hanya untuk exact parent dengan `status = DRAFT` dan `finalized_at IS NULL`. List/download tetap read-only berdasarkan authorization bila case kelak non-DRAFT, kecuali future finalized-case policy secara eksplisit mengubahnya. D2 tidak membangun finalization.

Tidak ada automatic evidence purge, scheduled deletion, atau retention cleanup. Retention/purge lanjutan deferred. Restrictive foreign keys mempertahankan evidence integrity. Cleanup object baru yang gagal dipersist adalah compensation operasi, bukan deletion lifecycle evidence tersimpan.

## 4. Representasi database yang disetujui

Satu model baru: `App\Models\PakCaseEvidence`. Satu table baru: **`pak_case_evidences`**, mengikuti plural Laravel convention. Satu additive migration untuk table tersebut.

| Kolom | Kontrak |
| --- | --- |
| `id` | Primary key |
| `pak_case_id` | FK `pak_cases.id`, restrict on delete |
| `disk` | Server-selected, selalu `local`; hidden storage metadata |
| `object_key` | Server-generated key sesuai section 5; hidden |
| `original_filename` | Sanitized bounded UTF-8 basename; metadata saja |
| `mime_type` | Server-detected MIME dalam allowlist |
| `size_bytes` | Ukuran aktual positif, maksimum 10,485,760 bytes |
| `checksum_sha256` | Server-generated lowercase 64-character hex |
| `uploaded_by` | FK `users.id`, restrict on delete |
| `created_at` | Waktu persistence/upload tercatat dari server |

Unique `(disk, object_key)`; index `(pak_case_id, created_at, id)`; index `uploaded_by`. **Tidak ada checksum uniqueness**; same content boleh menjadi evidence record terpisah. Parent menentukan person context; actor berasal dari authenticated session.

Metadata row immutable setelah insert. Model menolak update/delete melalui normal application model operations. Tidak ada `updated_at`. Tidak menambahkan `person_id`, `performance_evaluation_id`, `pak_conversion_run_id`, category, description, public URL, raw JSON, temporary path, supersession, atau replacement fields. Tidak ada cascade deletion evidence history.

## 5. Private storage, filename, MIME, dan checksum

Disk ditetapkan eksplisit **`local`**, bukan mengikuti `FILESYSTEM_DISK`. Reuse `storage/app/private`, `serve=false`; tidak ada disk baru, perubahan filesystem disk configuration, public storage/URL, atau symbolic-link access.

Object key exact: `pak/evidence/<32 lowercase hexadecimal characters>.bin`. Bagian random berasal dari **128 bits cryptographically secure randomness**. Original filename tidak menjadi bagian key. Disk/key/path tidak pernah menjadi browser authority. Tolak key malformed/traversal dan storage yang dapat diakses publik; jangan overwrite object existing saat collision.

| Tipe | Server-detected MIME | Extension filename yang kompatibel |
| --- | --- | --- |
| PDF | `application/pdf` | `.pdf` |
| JPEG | `image/jpeg` | `.jpg`, `.jpeg` |
| PNG | `image/png` | `.png` |

Maksimum **10 MiB = 10,485,760 bytes per file**. Ini UX/design policy D2, bukan regulatory claim. Content MIME dan filename extension wajib kompatibel; browser MIME header atau extension saja tidak cukup. Validasi authoritative berlangsung server-side.

Original filename: valid UTF-8, basename saja, control characters dibuang, blank/`.`/`..` ditolak, panjang dibatasi. Metadata filename tidak pernah dipakai sebagai storage path; escape saat ditampilkan dan sanitize kembali untuk response headers.

**SHA-256** dihitung server-side dari exact uploaded/stored bytes, lowercase 64 hex. Browser checksum tidak authoritative. Read-back verification bytes, MIME, size, dan checksum wajib sebelum persistence success diterima. Private paths, disk, key, checksum, temporary metadata, SQL, dan exceptions tidak diproyeksikan ke browser.

## 6. Temporary Livewire upload

Disetujui bounded modification `config/livewire.php`: temporary transport allowlist menjadi `xls`, `xlsx`, `pdf`, `jpg`, `jpeg`, `png`. Support XLS/XLSX existing dipertahankan; global temporary maximum tetap **15 MiB**. Action/service D2 tetap memberlakukan **10 MiB**.

Temporary disk tetap `local`, preview MIME tetap disabled, throttle/cleanup dan max upload time existing tidak berubah. Tidak ada public/inline preview D2. Focused regression wajib membuktikan transport expansion tidak melemahkan validasi XLS/XLSX pada SDM/SKP existing. Limit deployment PHP/web server bukan klaim acceptance dalam checkpoint ini.

## 7. Authorization

Tidak ada permission baru. Semua operasi wajib active actor dan `pak.access`; recheck server-side pada setiap query/action/download, bukan hanya visibility atau mount.

| Operasi | Permission tambahan lengkap |
| --- | --- |
| Read/list/metadata/download | `pak.case.view`, `pak.skp.view` |
| Upload | `pak.case.view`, `pak.case.update`, `pak.skp.view`, `pak.skp.manage` |

**Tidak membutuhkan `pak.employee.view`**, kecuali future surface secara eksplisit menavigasi employee detail; **tidak membutuhkan `pak.conversion.view`** untuk generic case evidence. Ini dedicated evidence authorization bundle, bukan mewarisi tambahan permission dari generic case-edit UI. Tidak mengubah existing case-edit policy.

Resolve exact authorized case, lalu evidence hanya dalam parent itu. Cross-case evidence ID gagal tertutup. Tidak menambah employee self-service ownership rule. Actor, parent identity, uploaded time, disk/key/checksum berasal dari server context, bukan arbitrary browser state.

## 8. Download dan response

Disetujui bounded authenticated route/controller yang menerima **case ID dan evidence ID saja**; tidak menerima disk, path, object key, atau filename. Boundary: `auth`, `EnsureUserIsActive`, throttle sesuai protected-download convention existing, serta authorization section 7.

Urutan: authorize actor → resolve authorized case → resolve evidence dalam exact case → validasi hidden storage metadata → baca exact private object → verifikasi size dan SHA-256 → stream **verified bytes**. Gunakan stream yang sama atau private verified staging agar bytes response sesuai bytes yang diverifikasi.

Foreign/missing record, missing physical object, invalid metadata, size mismatch, dan checksum mismatch fail closed. Tidak ada repair saat read/download. Permission denial tetap HTTP 403 sesuai boundary; jangan bocorkan keberadaan foreign record atau path.

Response: validated `Content-Type`, `Content-Disposition: attachment`, sanitized original filename, `X-Content-Type-Options: nosniff`, dan private/no-store cache behavior. Tidak menampilkan disk/key/filesystem path/temporary path/checksum/storage exception/SQL/stack trace. Tidak menyediakan public URL, public temporary link, atau inline preview.

## 9. Storage/SQL ordering dan failure compensation

Filesystem dan SQL **tidak diklaim atomic secara distributed transaction**. Urutan upload:

1. Authorize dan validasi upload.
2. Tulis brand-new randomly named private object.
3. Verifikasi stored bytes, MIME, size, checksum.
4. Mulai SQL transaction.
5. Reload/lock exact `PakCase`.
6. Re-authorize dan recheck DRAFT/non-finalized lifecycle.
7. Insert `PakCaseEvidence`.
8. Tulis approved upload audit event.
9. Commit.
10. Return persisted evidence, lalu refresh list dari persistence.

Storage failure sebelum row creation: tidak ada DB row. Confirmed SQL rollback setelah object creation: lakukan primary/write-DB reference check, lalu hapus **hanya object baru operasi ini** bila tidak direferensikan. Jangan menghapus object yang memiliki persisted evidence reference.

Uncertain commit: jangan langsung menghapus object atau mengklaim rollback. Periksa persisted state lewat primary/write DB; bila tetap uncertain, retain untuk operational reconciliation. Kegagalan reference check juga tidak mengotorisasi deletion. Cleanup failure/process crash dapat menyisakan orphan; tidak ada auto-repair saat render/download. Operational orphan reconciliation job/tooling di luar minimal D2.

Browser retry bukan return-existing success; checksum bukan idempotency key. Tidak ada automatic mutation retry. Berikan bounded safe messages untuk invalid/oversize/unsupported file, missing/stale parent, storage/DB/audit failure, dan upload yang belum dapat dikonfirmasi. Jangan meneruskan raw exception, SQL, trace, atau path; HTTP authorization denial tetap 403.

## 10. Audit event dan bounded D4 dependency extension

Disetujui tepat satu event baru: **`PAK_CASE_EVIDENCE_UPLOADED`**, subject **`PakCase`**, dengan case reference existing. Tidak membutuhkan audit subject type baru.

Payload exact yang diperbolehkan:

```text
after:
  case_id
  evidence_id
  mime_type
  size_bytes
```

Tidak menyimpan original filename, disk, object key, checksum, filesystem path, atau temporary path dalam audit payload. Event ditulis bersama insert evidence dalam SQL transaction; audit failure mengikuti rollback/compensation section 9. Tidak ada event download wajib. Tidak ada replace/delete event karena operasinya tidak tersedia.

D4 tetap FINAL/FROZEN. D2 mengotorisasi **bounded dependency extension** hanya untuk safe presentation event baru menggunakan event-specific allowlist existing, bukan membuka ulang D4 lifecycle. Possible files: `PakEventResource.php`, `ActivityDetailPresenter.php`, `Phase02AuditPresentationTest.php`. Tidak ada raw payload fallback atau filename/path/checksum exposure. Tidak mengubah audit writer semantics.

## 11. UI dan presentation

Lokasi minimal: existing `PakCaseResource/Pages/ViewCase.php`, section/modal **Dokumen pendukung kasus**. Tidak ada standalone top-level evidence resource dan tidak mengubah `CaseConversion`.

List bounded: original filename escaped, safe type label/MIME, human-readable size, uploaded by, uploaded time. Tidak menampilkan disk, object key, checksum, private/temporary path atau raw storage metadata. Supporting evidence dan workbook import provenance tidak diberi label seolah sama.

Upload adalah explicit action; drag/drop tidak langsung mempersist evidence. Browser tidak mengendalikan storage metadata. Successful upload me-refresh exact persisted list. Render/list/download read-only, tidak memanggil conversion services atau mengubah SKP provenance. Invalid stored metadata tidak direpair oleh presenter.

## 12. Implementation scope forecast

Forecast berikut hanya mengotorisasi scope bounded D2 setelah freeze efektif; bukan izin mengubah unrelated files.

| Kategori | File |
| --- | --- |
| MODIFY | `app/Filament/Pak/Resources/PakCaseResource/Pages/ViewCase.php` |
| MODIFY | `routes/web.php`, `config/livewire.php` |
| NEW model | `app/Models/PakCaseEvidence.php` |
| NEW migration | `database/migrations/<timestamp>_create_pak_case_evidences_table.php` |
| NEW authorization/query | `app/Domain/Pak/Authorization/PakEvidenceAuthorization.php`, `app/Domain/Pak/Queries/PakEvidenceQuery.php` |
| NEW services | `app/Domain/Pak/Services/PakEvidenceService.php`, `app/Domain/Pak/Services/PakEvidenceStorage.php` |
| NEW presenter | `app/Filament/Pak/Support/EvidencePresenter.php` |
| NEW controller | `app/Http/Controllers/PakEvidenceDownloadController.php` |
| NEW view | `resources/views/filament/pak/case-evidence.blade.php` |
| NEW tests | `tests/Feature/Pak/EvidenceStorageTest.php`, `EvidenceLifecycleTest.php`, `EvidenceUiTest.php`, `EvidenceDownloadTest.php` (semua pada direktori yang sama) |
| BOUNDED D4 extension | `app/Filament/Pak/Resources/PakEventResource.php`, `app/Filament/Pak/Support/ActivityDetailPresenter.php`, `tests/Feature/Pak/Phase02AuditPresentationTest.php` |

No semantic change: conversion services/DTOs/lifecycle, SKP provenance services, `PakSkpImportBatch`, `PakSkpImportRow`, `PerformanceEvaluation`, permissions, seeders, Composer, filesystem disk configuration. Tidak ada new disk/new permission. Tidak ada implementasi D1 atau Phase 02E.

## 13. Frozen test contract

Focused implementation tests wajib membuktikan:

- Authorized list/download/upload; setiap permission; inactive/revoked actor; cross-case ID; direct URL denial; DRAFT/non-finalized upload boundary; read/download policy untuk non-DRAFT.
- Genuine PDF/JPEG/PNG; MIME spoofing; extension/MIME mismatch; exact 10 MiB boundary, oversize, unsupported type; temporary transport XLS/XLSX tetap berfungsi tanpa melemahkan action-level validation.
- Random object key; traversal rejection; forged browser storage metadata; server SHA-256; read-back verification; safe UTF-8 basename; escaping; private storage; tanpa public URL/path leakage.
- Immutable row, normal model update/delete ditolak, tanpa overwrite/version-chain inference; same-content independent upload; explicit retry bukan idempotent success.
- Storage failure, DB failure compensation, audit failure compensation, uncertain commit, cleanup/reference-check failure; persisted reference tidak dihapus; missing object, size/checksum mismatch; zero repair saat read.
- No conversion service invocation/mutation dan no SKP provenance mutation; bounded audit event/presentation tanpa filename/key/checksum; zero-write list/download, tanpa download audit event wajib.

Regresi minimum existing: `PanelAuthorizationTest`, `PresentationPolishTest`, `FoundationServicesTest`, `FoundationIntegrityTest`, `ConversionReadPresentationTest`, `SkpSourceStorageTest`, `SkpImportPreparationTest`, `SkpImportCommitTest`, `SkpSourceModelTest`, `SkpImportRowLinkTest`, `ImportTemplateTest`, `ActivityDetailPresentationTest`, `Phase02AuditPresentationTest`, `SkpAuditBaselineTest`, plus empat focused D2 suites. Pak suites berada di `tests/Feature/Pak`; `ImportTemplateTest` di `tests/Feature`.

Tidak menjalankan full Phase 02E saat implementasi D2. Whole-phase regression, compatibility, final concurrency matrix, UAT, dan final verification tetap boundary 02E. Freeze ini tidak mencatat test execution atau acceptance implementasi baru.

## 14. Deferred dan acceptance

Tetap deferred: retention/purge lanjutan, evidence replacement/version chain, deletion/removal, evaluation-specific linkage, download audit logging, orphan reconciliation job/tooling, employee self-service evidence, public/inline preview. Tidak boleh diimplementasikan diam-diam.

Implementasi D2 baru dapat diterima setelah kontrak di atas diimplementasikan, focused tests/regressions dan quality/scope checks lulus. **Design freeze bukan D2 FINAL**, bukan UAT/deployment verification, dan bukan dimulainya Phase 02E.
