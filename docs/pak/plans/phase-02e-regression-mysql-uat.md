# Phase 02E — Regression / MySQL / UAT

**Status: FROZEN FOR EXECUTION — berlaku setelah commit dokumentasi plan ini.**

## 1. Baseline

- Branch: `feature/pak-phase-02`.
- Entry baseline: `c1c4daf` — formal closure Phase 02D.
- Phase 02D FINAL dan tidak dibuka kembali oleh 02E.
- 02D.1 production workbook adapter tetap BLOCKED / DEFERRED sebagai explicit non-blocking carry-forward sesuai documented reopen conditions.
- Phase 02E adalah verification/acceptance checkpoint; bukan authorization untuk business-rule, lifecycle, schema, permission, atau feature baru.

## 2. Scope

Phase 02E melaksanakan:

1. whole Phase 02 automated regression;
2. isolated fresh/upgrade schema compatibility;
3. final MariaDB/MySQL concurrency/race verification;
4. UAT;
5. final verification/result documentation;
6. deployment verification sesuai workflow proyek.

Tidak membuat top-level Phase 02 sub-slice baru. Lane berikut hanya execution ordering internal.

## 3. Lane A — Whole Phase 02 regression

Gunakan default `phpunit.xml` / SQLite in-memory untuk routine full regression.

Acceptance:

- seluruh executable Phase 02 regression lulus;
- shared Phase 01/SDM baseline tidak regression;
- skipped tests diklasifikasikan dan tidak dihitung sebagai passed evidence;
- opt-in MariaDB/concurrency tests dijalankan terpisah pada Lane C.

Tidak memperbaiki production behavior hanya untuk membuat broad suite hijau tanpa bounded defect review.

## 4. Lane B — Schema compatibility

Verifikasi pada database disposable/isolated:

- fresh-schema path;
- upgrade path;
- rollback/recovery path yang memang memiliki executable contract;
- constraints/index/FK yang relevan terhadap Phase 02.

Historical evidence bukan current 02E evidence.

Known compatibility fact:

- exact production target MariaDB 10.5.10 pernah gagal pada fresh-schema verification dengan SQLSTATE `42S22` / vendor `1054` pada legacy `import_batches.published_at` ALTER;
- MariaDB 10.5.11 historically passed fresh/rollback/upgrade verification;
- hasil 10.5.11 tidak boleh diklaim sebagai exact-production-version proof.

Tidak patch/skip migration hanya untuk melewati verification. Bila 10.5.10 masih gagal, klasifikasikan sebagai explicit compatibility/deployment blocker dan review secara bounded sebelum corrective implementation.

## 5. Lane C — Final MariaDB concurrency/race matrix

Authoritative harness: `tests/Support/Pak/CONCURRENCY.md`.

Environment wajib:

- isolated disposable MariaDB;
- image/version `mariadb:10.5.11` untuk existing strict concurrency contract;
- loopback `127.0.0.1`;
- host port `33318`;
- database `pak_02a1_test`;
- `PAK_DISPOSABLE_DB=pak_02a1_test`;
- `PAK_CONCURRENCY=1`;
- `PAK_CONCURRENCY_PORT=33318`;
- explicit disposable `DB_USERNAME` / `DB_PASSWORD`;
- tidak pernah memakai development/shared/production database atau credentials.

Gunakan fresh container untuk final 02E evidence. Jangan menganggap container atau run historis sebagai acceptance terbaru.

Semua required live concurrency tests harus zero unexpected failure dan zero unintended skip.

## 6. Lane D — UAT

UAT dilakukan bersama Tim SDM menggunakan data nyata yang dianonimkan bila diperlukan.

Wajib mencakup kontrak Phase 02 yang applicable, termasuk:

- source/provenance/revision;
- annual-subset dan fallback;
- segmentasi per bulan;
- exact numeric/golden behavior;
- trace;
- safe presentation BR-SKP-008 pending;
- private evidence;
- review metric;
- SDM-only / PAK-only / mixed permissions;
- inactive account;
- dua workspace;
- existing SDM operations.

`TC-PAK-029–038` dan `TC-PAK-006` tetap mandatory sesuai test specification.

02D.1 production workbook adapter-dependent UAT tetap BLOCKED / DEFERRED sampai reopen condition terpenuhi. Synthetic adapter tidak menjadi bukti production workbook mapping.

## 7. Lane E — Final verification dan deployment

Final result harus mencatat secara terpisah:

- automated regression;
- schema compatibility;
- MariaDB race matrix;
- UAT;
- remaining blockers/deferred items;
- exact production database compatibility;
- deployment verification.

Tidak mengklaim production readiness bila exact-production compatibility/deployment gate belum terpenuhi.

Tidak mengklaim business finalization. Phase 02 tetap hanya memakai lifecycle/status accepted sebelumnya.

## 8. Carry-forward

Tetap tidak diselesaikan oleh 02E secara asumtif:

- D1 production workbook adapter blocker;
- OD-07 / BR-SKP-008 BUSINESS PENDING;
- OD-08 DEFERRED;
- pending rules lain yang sebelumnya dinyatakan outside/non-blocking.

Setiap corrective implementation yang ditemukan oleh 02E harus dibatasi pada defect yang terbukti, direview sebelum perubahan, diuji focused terlebih dahulu, lalu broad verification diulang sesuai impact.

## 9. Execution order

Urutan default:

1. repository/baseline verification;
2. Lane A whole regression;
3. Lane B schema compatibility;
4. Lane C fresh disposable MariaDB final concurrency matrix;
5. Lane D UAT;
6. Lane E final result/deployment verification.

Stop gate berlaku: bila sebuah lane menemukan unexpected defect/blocker, hentikan broad progression, dokumentasikan evidence, lakukan bounded review, dan jangan membuat unrelated corrective changes.

## 10. Acceptance boundary

Phase 02E baru dapat dinyatakan selesai setelah seluruh applicable gate memiliki current evidence dan blocker/deferred item dicatat secara eksplisit.

Historical evidence dapat menjadi provenance tetapi bukan pengganti current execution evidence.

Dokumen ini tidak menjalankan tests, migration, container, UAT, deployment, atau source-code change.
