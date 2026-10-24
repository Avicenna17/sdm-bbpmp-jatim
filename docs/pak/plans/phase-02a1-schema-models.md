# FINAL PLAN Phase 02A.1 — Schema, Migrations, Enums, dan Models

## 1. Scope dan dasar arsitektur

Scope tetap tepat:

- **3 tabel:** `performance_evaluations`, `pak_skp_import_batches`, `pak_skp_import_rows`.
- **3 model** untuk tabel tersebut.
- **6 PHP enums**.
- Relationships, casts, foundation guards, fixture requirements, dan automated test plan 02A.1.

Dasar repository yang telah diaudit:

- Laravel 12, PHP 8.2+, domain PAK di `app/Domain/Pak/`.
- `people.id` adalah canonical identity; key people/users menggunakan BIGINT unsigned.
- FK PAK menggunakan RESTRICT.
- `PakModel` melarang delete melalui Eloquent; model events tidak melindungi direct SQL/bulk query.
- PHPUnit default memakai SQLite in-memory. Verifikasi MySQL/MariaDB memerlukan database disposable.
- `FoundationSchemaTest` perlu menyesuaikan urutan teardown tabel dependent pada pengujian upgrade.
- Metadata lampiran evaluasi tambahan tetap ditunda; provenance workbook tersedia melalui batch/row.

Tidak membuat adapter, serializer implementation, import/correction service, evidence storage, policy, permission seeder, resolver, calculator, atau UI.

## 2. Schema dan migration sequence

### Konvensi

- PK: BIGINT UNSIGNED auto increment.
- Semua FK: BIGINT UNSIGNED, `ON DELETE RESTRICT`, tanpa cascading update.
- Status/type: VARCHAR + PHP backed enum, bukan database ENUM.
- Kolom nullable default NULL.
- Kolom NOT NULL tanpa default kecuali dinyatakan eksplisit.
- `created_at`/`updated_at`: TIMESTAMP nullable mengikuti migration convention existing; diisi Eloquent.
- JSON tanpa database default expression.
- Hash/fingerprint: CHAR(64), lowercase hexadecimal.
- Tidak mengubah migration lama atau melakukan backfill SKP dari DUK.

### Urutan migration

1. `2026_10_23_000100_create_performance_evaluations.php`
2. `2026_10_23_000200_create_pak_skp_import_batches.php`
3. `2026_10_23_000300_create_pak_skp_import_rows.php`

### `performance_evaluations`

| Column | Type | Nullable/default | FK |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK | NOT NULL, auto increment | — |
| `person_id` | BIGINT UNSIGNED | NOT NULL | `people.id` |
| `performance_year` | SMALLINT UNSIGNED | NOT NULL | — |
| `evaluation_type` | VARCHAR(30) | NOT NULL | — |
| `source_kind` | VARCHAR(30) | NOT NULL | — |
| `source_reference` | VARCHAR(255) | NOT NULL | — |
| `source_record_identifier` | VARCHAR(255) | NULL | — |
| `predicate_code` | VARCHAR(50) | NOT NULL | — |
| `predicate_label_original` | VARCHAR(255) | NOT NULL | — |
| `coverage_start` | DATE | NOT NULL | — |
| `coverage_end` | DATE | NOT NULL | — |
| `source_date` | DATE | NULL | — |
| `source_position_name` | VARCHAR(255) | NULL | — |
| `source_functional_level_label` | VARCHAR(255) | NULL | — |
| `source_position_tmt` | DATE | NULL | — |
| `source_sk_number` | VARCHAR(150) | NULL | — |
| `source_sk_date` | DATE | NULL | — |
| `employee_position_history_id` | BIGINT UNSIGNED | NULL | `employee_position_histories.id` |
| `historical_reason` | TEXT | NULL | — |
| `source_identity_fingerprint` | CHAR(64) | **NOT NULL** | — |
| `content_fingerprint` | CHAR(64) | **NOT NULL** | — |
| `current_source_identity_fingerprint` | CHAR(64) | NULL | — |
| `status` | VARCHAR(30) | NOT NULL, default `CURRENT` | — |
| `previous_version_id` | BIGINT UNSIGNED | NULL | self `id` |
| `revision_reason` | TEXT | NULL | — |
| `created_by` | BIGINT UNSIGNED | NOT NULL | `users.id` |
| `superseded_by_user_id` | BIGINT UNSIGNED | NULL | `users.id` |
| `superseded_at` | TIMESTAMP | NULL | — |
| `created_at`, `updated_at` | TIMESTAMP | NULL | — |

Indexes:

| Nama | Columns | Jenis |
|---|---|---|
| `pe_current_source_uq` | `current_source_identity_fingerprint` | UNIQUE |
| `pe_previous_version_uq` | `previous_version_id` | UNIQUE |
| `pe_source_identity_idx` | `source_identity_fingerprint` | non-unique |
| `pe_person_year_type_status_idx` | `person_id, performance_year, evaluation_type, status` | non-unique |
| `pe_person_coverage_idx` | `person_id, coverage_start, coverage_end` | non-unique |
| `pe_position_history_idx` | `employee_position_history_id` | non-unique |
| `pe_created_by_idx` | `created_by` | non-unique |
| `pe_superseded_by_idx` | `superseded_by_user_id` | non-unique |

Tidak ada unique person/year/type, larangan overlap evidence global, predicate-rule FK, coefficient, atau nilai AK.

### `pak_skp_import_batches`

| Column | Type | Nullable/default | FK |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK | NOT NULL, auto increment | — |
| `disk` | VARCHAR(50) | **NOT NULL, tanpa default** | — |
| `object_key` | VARCHAR(500) | NOT NULL | — |
| `original_filename` | VARCHAR(255) | NOT NULL | — |
| `checksum_sha256` | CHAR(64) | NOT NULL | — |
| `adapter_key` | VARCHAR(100) | NOT NULL | — |
| `adapter_version` | VARCHAR(50) | NOT NULL | — |
| `schema_version` | VARCHAR(50) | NOT NULL | — |
| `status` | VARCHAR(30) | NOT NULL, default `PARSING` | — |
| `created_by` | BIGINT UNSIGNED | NOT NULL | `users.id` |
| `committed_by` | BIGINT UNSIGNED | NULL | `users.id` |
| `committed_at` | TIMESTAMP | NULL | — |
| `summary` | JSON | NULL | — |
| `error_metadata` | JSON | NULL | — |
| `created_at`, `updated_at` | TIMESTAMP | NULL | — |

Disk dipilih eksplisit oleh service/config pada checkpoint berikutnya; tidak ada model fallback ke `local`.

Indexes:

- `psib_status_created_idx`: `(status, created_at)`.
- `psib_checksum_idx`: `(checksum_sha256)`, non-unique.
- `psib_created_by_idx`: `(created_by)`.
- `psib_committed_by_idx`: `(committed_by)`.

Tidak ada unique checksum atau object key yang menghalangi provenance import ulang.

### `pak_skp_import_rows`

| Column | Type | Nullable/default | FK |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK | NOT NULL, auto increment | — |
| `batch_id` | BIGINT UNSIGNED | NOT NULL | `pak_skp_import_batches.id` |
| `record_ordinal` | INTEGER UNSIGNED | NOT NULL | — |
| `source_sheet` | VARCHAR(255) | NULL | — |
| `source_row` | INTEGER UNSIGNED | NULL | — |
| `source_locator` | JSON | NULL | — |
| `person_id` | BIGINT UNSIGNED | NULL | `people.id` |
| `person_resolution_status` | VARCHAR(30) | NOT NULL, default `PENDING` | — |
| `match_method` | VARCHAR(50) | NULL | — |
| `source_reference` | VARCHAR(255) | NULL | — |
| `source_record_identifier` | VARCHAR(255) | NULL | — |
| `source_identity_fingerprint` | CHAR(64) | NULL | — |
| `content_fingerprint` | CHAR(64) | NULL | — |
| `normalized_payload` | JSON | NULL | — |
| `raw_provenance` | JSON | NULL | — |
| `processing_status` | VARCHAR(30) | NOT NULL, default `PENDING` | — |
| `issue_codes` | JSON | NULL | — |
| `error_metadata` | JSON | NULL | — |
| `performance_evaluation_id` | BIGINT UNSIGNED | NULL | `performance_evaluations.id` |
| `created_at`, `updated_at` | TIMESTAMP | NULL | — |

Indexes:

- `psir_batch_ordinal_uq`: UNIQUE `(batch_id, record_ordinal)`.
- `psir_person_idx`: `(person_id)`.
- `psir_evaluation_idx`: `(performance_evaluation_id)`.
- `psir_source_identity_idx`: `(source_identity_fingerprint)`.
- `psir_content_idx`: `(content_fingerprint)`.

Composite unique menyediakan akses dengan prefix `batch_id`; tidak menambah index identik.

### Rollback

Urutan `down()`: rows → batches → evaluations. Tidak menonaktifkan FK global, mengubah schema Phase 01, atau menghapus object storage.

Rollback setelah data operasional tersedia memerlukan backup/recovery. Fresh, upgrade, dan rollback tests menggunakan database disposable.

## 3. Enums, models, casts, dan invariants

### Enums

| Enum | Cases |
|---|---|
| `PerformanceEvaluationType` | `Q1`, `Q2`, `Q3`, `Q4`, `ANNUAL`, `CUSTOM_PERIOD` |
| `PerformanceSourceKind` | `OFFICIAL`, `PROVISIONAL` |
| `PerformanceEvaluationStatus` | `CURRENT`, `SUPERSEDED` |
| `SkpImportBatchStatus` | `PARSING`, `INVALID`, `READY`, `COMMITTED`, `FAILED` |
| `SkpImportRowStatus` | `PENDING`, `READY`, `IMPORTED`, `DUPLICATE`, `CONFLICT`, `IDENTITY_AMBIGUOUS`, `INVALID`, `FAILED` |
| `SkpPersonResolutionStatus` | `PENDING`, `MATCHED`, `NOT_FOUND`, `AMBIGUOUS`, `INVALID` |

Makna status tetap:

- Batch INVALID: kegagalan kontrak yang diketahui; FAILED: technical/unexpected failure.
- COMMITTED: operasi import telah difinalisasi sesuai kontrak, bukan finalisasi bisnis PAK.
- Row IMPORTED: menghasilkan evaluasi baru; DUPLICATE: identik dengan CURRENT existing; CONFLICT: identity sama/content berbeda.
- IDENTITY_AMBIGUOUS: identitas record sumber tidak deterministik.
- Person MATCHED: tepat satu `people.id`; AMBIGUOUS: lebih dari satu kandidat valid.
- Detail issue disimpan terpisah melalui issue codes/error metadata.

`CUSTOM_PERIOD` dan `PROVISIONAL` hanya representasi historis, tanpa active options, seeder, adapter, atau input flow baru.

### Models dan relationships

Ketiga model extends `PakModel`; kelas global tersebut tidak diubah.

| Model | Relationships |
|---|---|
| `PerformanceEvaluation` | `person`, `positionHistory`, `predecessor`, `successor`, `creator`, `supersededByUser`, `importRows` |
| `PakSkpImportBatch` | `rows`, `creator`, `committer` |
| `PakSkpImportRow` | `batch`, `person`, `performanceEvaluation` |

`predecessor` memakai belongsTo dan `successor` memakai hasOne melalui `previous_version_id`. Tambahkan `Person::performanceEvaluations()`.

Casts:

- Type/status: enum terkait.
- Tahun/ordinal/source row: integer.
- DATE: `immutable_date`.
- TIMESTAMP: `immutable_datetime`.
- JSON: array.
- Fingerprint/source identifiers: string; tanpa hashing atau normalization otomatis.

Storage reference, raw/normalized provenance, dan error metadata disembunyikan dari serialisasi default. Ini bukan pengganti authorization.

### PerformanceEvaluation invariants

- Normal insert hanya CURRENT; direct SUPERSEDED melalui normal create ditolak.
- SUPERSEDED hanya dicapai melalui CURRENT → SUPERSEDED.
- Source/content fingerprint wajib lowercase hexadecimal tepat 64 karakter.
- CURRENT wajib current fingerprint identik dengan source fingerprint.
- SUPERSEDED wajib current fingerprint NULL; historical fingerprints tetap tersimpan.
- CURRENT tidak memiliki supersession actor/time; SUPERSEDED wajib memiliki keduanya.
- Source reference OFFICIAL wajib non-blank.
- Coverage start ≤ end dan berada pada performance year.
- Optional history link harus milik person yang sama.
- Existing identity/content/provenance immutable. Update hanya metadata supersession dan `updated_at`.
- SUPERSEDED tidak dapat dipulihkan menjadi CURRENT atau diubah.
- Successor mempertahankan seluruh identity-defining fields dan source fingerprint predecessor; content fingerprint harus berbeda.

**Revision reason:**

- `previous_version_id = NULL` → `revision_reason = NULL`.
- `previous_version_id` non-NULL → `revision_reason` wajib non-blank.
- `historical_reason` tetap terpisah dan tidak menggantikan revision reason.

Model hanya memeriksa nilai fingerprint yang diberikan. Locking, serializer, canonical `NO_CHANGES`, atomic successor/audit, dan concurrent correction tetap 02A.2+.

### Import batch invariants dan closure

- `disk` wajib eksplisit, tanpa default schema/model.
- `checksum_sha256` wajib lowercase hexadecimal tepat 64 karakter.
- Metadata provenance awal immutable: disk/object key, filename, checksum, adapter/schema version, creator.
- Update hanya status, commit metadata, summary/error metadata, dan timestamps.
- COMMITTED wajib `committed_by` dan `committed_at`.
- Non-COMMITTED wajib kedua metadata commit NULL.
- **Transisi ke COMMITTED ditolak jika masih ada row PENDING atau READY.**
- **Setelah COMMITTED, tidak boleh membuat row baru pada batch tersebut.**
- Batch COMMITTED dan semua rows existing miliknya immutable.
- COMMITTED tidak berarti seluruh row IMPORTED. Outcome seperti DUPLICATE, CONFLICT, IDENTITY_AMBIGUOUS, dan INVALID tetap dapat dipertahankan sebagai provenance.

Penegasan ini tidak menentukan business rule tambahan mengenai keberhasilan setiap row. Koordinasi locking antara row creation dan batch closure tetap tanggung jawab service pada 02A.2+.

### Import row invariants

Source/content fingerprint yang non-null wajib lowercase hexadecimal tepat 64 karakter.

READY, IMPORTED, DUPLICATE, dan CONFLICT wajib memiliki:

- `person_resolution_status = MATCHED`;
- `person_id` non-null;
- **`source_reference` non-null dan non-blank**;
- `source_identity_fingerprint` non-null;
- `content_fingerprint` non-null.

Ketentuan lain:

- IMPORTED/DUPLICATE wajib `performance_evaluation_id`.
- CONFLICT boleh tanpa `performance_evaluation_id`.
- MATCHED memerlukan canonical person; status resolution lain tidak mengklaim canonical person.
- Jika evaluation link tersedia, person harus konsisten.
- PENDING sebelum resolution dan row invalid/ambiguity/failed sebelum pembentukan fingerprint dapat disimpan tanpa fingerprint.
- Batch, ordinal, lokasi sumber, dan raw provenance immutable setelah insert.
- Resolution/normalization/classification dapat dilengkapi sebelum batch committed.
- Row creation pada batch COMMITTED ditolak; rows existing pada batch tersebut immutable.
- Ordinal/source row, bila tersedia, minimal 1.

### Batas enforcement

DB menegakkan NOT NULL, FK, dan UNIQUE. Model guards menegakkan invariant lintas field dan immutability melalui Eloquent.

Direct SQL/bulk query bukan domain write path yang sah. Tidak mengubah `PakModel` global atau menambah trigger/engine-specific CHECK. Model guards tidak diklaim memberikan jaminan concurrency untuk operasi lintas record; service enforcement, locking, serializer, dan koordinasi transaksi tetap 02A.2+.

## 4. Files dan automated test plan

### Files yang dibuat saat implementasi nanti

- Tiga migrations sesuai sequence.
- Tiga models dan enam enums sesuai daftar.
- `tests/Feature/Pak/SkpSourceSchemaTest.php`.
- `tests/Feature/Pak/SkpSourceModelTest.php`.
- `tests/Feature/Pak/SkpSourceMigrationTest.php`.
- `tests/Support/Pak/SkpSourceFixtures.php`.
- Konfigurasi PHPUnit terpisah untuk database disposable MySQL/MariaDB, tanpa credentials tersimpan.

Files existing yang diubah nanti:

- `app/Models/Person.php`: relationship.
- `tests/Feature/Pak/FoundationSchemaTest.php`: urutan teardown/rebuild dependency migration.

Tidak mengubah dependency, permissions, importer SDM, atau model global.

### Fixtures

Gunakan PersonFactory/UserFactory existing dan fixture builder khusus. Data sintetis serta fingerprint valid diberikan eksplisit; tidak membuat serializer tiruan.

Fixture SUPERSEDED dibentuk melalui create CURRENT lalu transisi yang diizinkan. Sediakan correction chain, source identity sama/content berbeda, batch gagal, invalid row tanpa fingerprint, duplicate dengan evaluation existing, serta committed batch dengan mixed final outcomes.

### Test matrix

| Area | Test wajib |
|---|---|
| Schema | Columns, tipe, nullability, defaults, FK, indexes; status/type bukan DB ENUM |
| Disk | NOT NULL tanpa default; insert tanpa disk ditolak |
| Batch checksum | Uppercase, non-hex, panjang selain 64, atau kosong ditolak |
| Row fingerprints | Non-null harus lowercase hex 64; NULL diterima hanya pada state yang mengizinkan |
| Domain fingerprints | Evaluasi tidak tersimpan tanpa source/content fingerprint valid |
| Nullable UNIQUE | Banyak NULL diizinkan; duplicate non-null current identity/predecessor ditolak |
| Normal insert | CURRENT berhasil; direct SUPERSEDED ditolak |
| Lifecycle | CURRENT projection sama; supersession mengosongkan projection dan mempertahankan history |
| Correction identity/content | Identity berbeda atau content sama ditolak; satu direct successor; tidak restore SUPERSEDED |
| Revision reason | Initial evaluation dengan revision reason non-NULL ditolak; successor tanpa reason atau reason blank ditolak; historical reason tidak menggantikannya |
| Row readiness | READY/IMPORTED/DUPLICATE/CONFLICT wajib MATCHED, person, source reference, dan kedua fingerprints |
| Source reference | Keempat status tersebut menolak NULL, string kosong, dan whitespace-only reference |
| Evaluation link | IMPORTED/DUPLICATE tanpa evaluation ditolak; CONFLICT tanpa evaluation diperbolehkan |
| Commit metadata | COMMITTED tanpa actor/time ditolak; non-COMMITTED dengan metadata commit ditolak |
| Batch closure | Transisi ke COMMITTED dengan row PENDING atau READY ditolak |
| Closed batch insertion | Create row baru pada batch COMMITTED ditolak |
| Mixed committed batch | DUPLICATE/CONFLICT/IDENTITY_AMBIGUOUS/INVALID tetap dapat dipertahankan; tidak memaksa semua row IMPORTED |
| Immutability | Domain mutation/delete ditolak; batch committed dan existing rows immutable |
| Relationships/casts | Relasi, nullable links, person consistency, enum, JSON, date/timestamp round-trip |
| Provenance | Invalid/ambiguity/conflict dapat disimpan tanpa evaluasi baru |
| Fresh/upgrade | Schema baru berhasil; data Phase 01 utuh |
| Rollback | Reverse dependency order benar; migrate ulang berhasil |
| Regression | Tests Phase 01 tetap lulus setelah penyesuaian upgrade fixture |

Semua masih **test plan**, bukan executable tests yang dibuat sekarang.

Concurrency tests 02A.1 membuktikan constraint database dengan dua koneksi disposable: maksimal satu commit untuk current identity/predecessor yang sama. Service classification, correction orchestration, dan race antara row creation dengan batch closure diuji pada checkpoint service berikutnya.

## 5. Portability, acceptance, dan deferred work

### Portability

- Uji SQLite dan MySQL/MariaDB sesuai versi deployment aktual.
- Nullable UNIQUE mengizinkan banyak SUPERSEDED dengan current fingerprint NULL dan banyak initial records dengan predecessor NULL.
- Gunakan InnoDB dan constraint names pendek.
- Tidak bergantung pada partial index, generated column, JSON index, atau database ENUM.
- Jangan menyimpulkan production compatibility hanya dari SQLite atau keberadaan binary lokal.
- DDL lintas migrations tidak diasumsikan atomik; recovery memeriksa migration yang sudah berhasil.
- Fresh/rollback/concurrency tests hanya pada database disposable.

### Acceptance 02A.1

Checkpoint selesai ketika:

1. Tepat tiga tabel, tiga model, dan enam enum tersedia.
2. Schema, RESTRICT FK, nullable UNIQUE, relationships, casts, dan guards sesuai plan.
3. Invariant disk/hash/row/source-reference/commit/closure/lifecycle/revision-reason teruji.
4. Provenance invalid/duplicate/conflict/ambiguity dapat disimpan tanpa evaluasi baru.
5. Fresh, upgrade, rollback, model tests, dan regression Phase 01 lulus.
6. Database deployment-equivalent telah diuji; tanpa itu portability acceptance belum selesai.
7. Tidak ada implementasi di luar scope.

### Ditunda ke 02A.2+

- Canonical serializer implementation.
- Correction/import services, authorization, locking, transactions, audit, dan concurrency classification.
- Adapter client serta verified sanitized representative workbook.
- Evidence storage, compensation, dan metadata lampiran tambahan.
- Source selection, conversion, dan seluruh checkpoint 02B–02E.
- UI tetap 02D.

Business rules dan pending decisions tidak berubah. Tidak ada file repository yang diubah, executable test yang dibuat, atau tindakan commit/push pada corrective pass ini.
