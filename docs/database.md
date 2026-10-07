> **Dokumen desain awal.** Ketentuan periode/publikasi Peta Jabatan pada dokumen ini telah digantikan oleh [panduan publikasi mandiri](publikasi-duk-dan-peta-jabatan.md). Gunakan migrasi Laravel sebagai acuan skema terkini.

# Database Design
## Dashboard Monitoring SDM BBPMP Provinsi Jawa Timur

**Target DBMS:** MySQL 8.0.x
**ORM:** Laravel Eloquent
**Schema source of truth:** Laravel migrations
**Client GUI:** MySQL Workbench

> MySQL Workbench adalah client/GUI. Jangan menjadikan perubahan manual di Workbench sebagai source of truth. Seluruh struktur database produksi harus dibuat melalui migration Laravel.

---

## 1. Prinsip Desain

Database harus mendukung:

- data bulanan;
- histori periode;
- import ulang periode yang sama;
- overwrite otomatis tanpa edit manual;
- pemisahan master person dan snapshot atribut bulanan;
- Peta Jabatan/Kebutuhan per periode;
- proyeksi tahun yang dinamis;
- audit import;
- public dashboard agregat;
- export terfilter.

Model yang direkomendasikan adalah **master + snapshot**:

- `people` menyimpan identitas stabil;
- `personnel_snapshots` menyimpan kondisi orang pada bulan tertentu;
- `position_requirement_snapshots` menyimpan kondisi peta jabatan pada bulan tertentu;
- `position_projection_values` menyimpan nilai proyeksi per tahun;
- `reporting_periods` mengatur draft/published;
- `import_batches` dan `import_issues` menyimpan audit.

---

## 2. Entity Relationship Diagram

```mermaid
erDiagram
    USERS ||--o{ IMPORT_BATCHES : uploads
    REPORTING_PERIODS ||--o{ IMPORT_BATCHES : has
    REPORTING_PERIODS ||--o{ PERSONNEL_SNAPSHOTS : contains
    REPORTING_PERIODS ||--o{ POSITION_REQUIREMENT_SNAPSHOTS : contains

    PEOPLE ||--o{ PERSONNEL_SNAPSHOTS : has

    IMPORT_BATCHES ||--o{ PERSONNEL_SNAPSHOTS : source
    IMPORT_BATCHES ||--o{ POSITION_REQUIREMENT_SNAPSHOTS : source
    IMPORT_BATCHES ||--o{ IMPORT_ISSUES : reports

    POSITION_REQUIREMENT_SNAPSHOTS ||--o{ POSITION_PROJECTION_VALUES : has

    REPORTING_PERIODS {
        bigint id PK
        date period_month UK
        varchar status
        timestamp published_at
        bigint published_by FK
        timestamps
    }

    PEOPLE {
        bigint id PK
        char person_key UK
        varchar nip UK
        varchar canonical_name
        varchar normalized_name
        timestamps
    }

    PERSONNEL_SNAPSHOTS {
        bigint id PK
        bigint reporting_period_id FK
        bigint person_id FK
        bigint import_batch_id FK
        varchar employment_group
        varchar employment_status
        varchar rank_name
        varchar grade_code
        varchar position_name
        tinyint position_class
        varchar placement_current
        varchar placement_initial
        varchar assignment_detail
        varchar education_level
        char gender
        json raw_payload
        timestamps
    }

    POSITION_REQUIREMENT_SNAPSHOTS {
        bigint id PK
        bigint reporting_period_id FK
        bigint import_batch_id FK
        char position_key
        varchar parent_org
        varchar work_unit
        varchar position_name
        varchar position_type
        tinyint position_class
        smallint retirement_age
        int incumbent_count
        int requirement_count
        int vacancy_count
        varchar requirement_status
        int retirement_5y_total
        json raw_payload
        timestamps
    }

    POSITION_PROJECTION_VALUES {
        bigint id PK
        bigint position_requirement_snapshot_id FK
        varchar metric_type
        smallint projection_year
        int value
    }

    IMPORT_BATCHES {
        bigint id PK
        bigint reporting_period_id FK
        bigint uploaded_by FK
        varchar source_type
        varchar original_filename
        char sha256
        varchar disk
        varchar path
        varchar status
        int total_rows
        int valid_rows
        int warning_rows
        int error_rows
        json summary
        timestamp committed_at
        timestamps
    }

    IMPORT_ISSUES {
        bigint id PK
        bigint import_batch_id FK
        int source_row
        varchar severity
        varchar field_name
        varchar code
        text message
        json row_payload
        timestamps
    }
```

---

## 3. Table `reporting_periods`

Merepresentasikan satu bulan pelaporan.

### Columns

| Column | Type | Rule |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| period_month | DATE | unique, selalu tanggal 1 |
| status | VARCHAR(20) | `draft`, `ready`, `published` |
| published_at | TIMESTAMP NULL | waktu publish |
| published_by | BIGINT UNSIGNED NULL | FK users |
| created_at | TIMESTAMP | Laravel |
| updated_at | TIMESTAMP | Laravel |

### Example

`2026-09-01` mewakili September 2026.

### Index

- UNIQUE `(period_month)`
- INDEX `(status, period_month)`

### Catatan

Public dashboard memilih periode published terbaru:

```sql
SELECT *
FROM reporting_periods
WHERE status = 'published'
ORDER BY period_month DESC
LIMIT 1;
```

---

## 4. Table `people`

Master identity. Jangan menyimpan jabatan/status terkini di sini karena atribut tersebut berubah per bulan.

### Columns

| Column | Type | Rule |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| person_key | CHAR(64) | unique SHA-256 app-level identity |
| nip | VARCHAR(32) NULL | unique jika valid |
| canonical_name | VARCHAR(255) | display name |
| normalized_name | VARCHAR(255) | fallback matching |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

### `person_key`

Rule:

```text
Jika NIP valid:
sha256("nip:" + normalized_nip)

Jika NIP tidak tersedia:
sha256("name:" + normalized_full_name)
```

### Mengapa tidak `nama` saja?

Karena:

- gelar dapat berubah;
- punctuation dapat berubah;
- typo dapat terjadi;
- dua orang dapat mempunyai nama sama.

NIP/NIP3K harus menjadi identifier utama bila tersedia.

### Validasi NIP

Nilai seperti `-`, nama jabatan, atau teks lain tidak boleh dianggap sebagai NIP.

---

## 5. Table `personnel_snapshots`

Satu row merepresentasikan kondisi satu person pada satu periode.

### Columns

| Column | Type | Nullable | Notes |
|---|---|---:|---|
| id | BIGINT UNSIGNED | no | PK |
| reporting_period_id | BIGINT UNSIGNED | no | FK |
| person_id | BIGINT UNSIGNED | no | FK |
| import_batch_id | BIGINT UNSIGNED | no | FK |
| source_row_no | INT | yes | nomor baris Excel untuk trace |
| source_sequence | INT | yes | kolom NO sumber, bukan PK |
| employment_group | VARCHAR(20) | no | `ASN`, `PPNPN`, `UNKNOWN` |
| employment_status | VARCHAR(40) | yes | PNS, PPPK, PPPK_PARUH_WAKTU, PPNPN |
| rank_name | VARCHAR(150) | yes | Pangkat |
| grade_code | VARCHAR(30) | yes | Gol |
| position_name | VARCHAR(255) | yes | Jabatan |
| position_class | TINYINT UNSIGNED | yes | Kelas Jabatan |
| placement_current | VARCHAR(255) | yes | Tim/penempatan terbaru |
| placement_initial | VARCHAR(255) | yes | Penempatan awal |
| assignment_detail | VARCHAR(255) | yes | detail/program tambahan dari source |
| education_level | VARCHAR(50) | yes | canonical education |
| education_raw | VARCHAR(100) | yes | original value |
| gender | CHAR(1) | yes | `L` / `P` |
| raw_payload | JSON | yes | source row original |
| created_at | TIMESTAMP | no | |
| updated_at | TIMESTAMP | no | |

### Constraints

- UNIQUE `(reporting_period_id, person_id)`
- FK period → `reporting_periods`
- FK person → `people`
- FK import → `import_batches`

### Indexes

- INDEX `(reporting_period_id, employment_group)`
- INDEX `(reporting_period_id, employment_status)`
- INDEX `(reporting_period_id, gender)`
- INDEX `(reporting_period_id, education_level)`
- INDEX `(reporting_period_id, grade_code)`
- INDEX `(reporting_period_id, position_class)`
- INDEX `(reporting_period_id, position_name(100))`

Untuk dataset kecil, index dapat disederhanakan. Jangan membuat terlalu banyak index sebelum melihat query nyata.

---

## 6. Mapping DUK `DUK PEGAWAI`

Recommended mapping:

| Excel | Database |
|---|---|
| NO | `source_sequence` |
| NAMA | `people.canonical_name` |
| NIP/NIP3K | `people.nip` |
| PANGKAT | `personnel_snapshots.rank_name` |
| GOL | `personnel_snapshots.grade_code` |
| JABATAN | `personnel_snapshots.position_name` |
| KELAS JABATAN | `personnel_snapshots.position_class` |
| PENEMPATAN / SK Tim Kerja Baru | `placement_current` |
| SK Tim Kerja Awal | `placement_initial` |
| kolom detail penempatan/program tambahan | `assignment_detail` |
| PENDIDIKAN | `education_level` + `education_raw` |
| JENIS KELAMIN | `gender` |
| STATUS | `employment_status` |

Karena struktur header DUK mempunyai merged/multi-row header, importer harus menggunakan mapping yang eksplisit dan bukan hanya `HeadingRowImport` default tanpa penyesuaian.

---

## 7. Employment Mapping

Application mapping:

```php
PNS                -> group ASN
PPPK               -> group ASN
PPPK Paruh Waktu   -> group ASN
PPNPN              -> group PPNPN
blank/unknown      -> group UNKNOWN
```

Canonical value yang disarankan:

```text
PNS
PPPK
PPPK_PARUH_WAKTU
PPNPN
UNKNOWN
```

Jangan menggunakan MySQL ENUM bila ingin mudah menambah status baru. Gunakan `VARCHAR` + validation/application enum PHP.

---

## 8. Education Normalization

Contoh canonical mapping:

```text
D IV
DIV
D4          -> D4

SLTP
SMP         -> SMP/SLTP  (pilih satu canonical code)

SMA
SMEA
SMK         -> tetap dibedakan bila dashboard membutuhkannya
```

Rekomendasi: simpan `education_raw` agar nilai source tidak hilang.

---

## 9. Table `position_requirement_snapshots`

Satu row = satu jabatan/unit pada satu periode.

### Columns

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| reporting_period_id | BIGINT UNSIGNED | FK |
| import_batch_id | BIGINT UNSIGNED | FK |
| source_row_no | INT NULL | row Excel |
| position_key | CHAR(64) | identity within period |
| parent_org | VARCHAR(255) NULL | Unit Organisasi Induk |
| work_unit | VARCHAR(255) NULL | Satuan Kerja |
| position_name | VARCHAR(255) | Nama Jabatan |
| position_type | VARCHAR(100) NULL | Jenis Jabatan |
| position_class | TINYINT UNSIGNED NULL | Kelas Jabatan |
| retirement_age | SMALLINT UNSIGNED NULL | Usia Pensiun |
| incumbent_count | INT UNSIGNED NULL | Jumlah Pemangku |
| requirement_count | INT UNSIGNED NULL | Jumlah Kebutuhan |
| vacancy_count | INT NULL | Jumlah Kosong |
| requirement_status | VARCHAR(50) NULL | source status |
| retirement_5y_total | INT UNSIGNED NULL | total |
| raw_payload | JSON NULL | original row |
| timestamps | TIMESTAMP | Laravel |

### Unique rule

Recommended:

- UNIQUE `(reporting_period_id, position_key)`

`position_key` dibentuk dari:

```text
sha256(
  normalized_parent_org + "|" +
  normalized_work_unit + "|" +
  normalized_position_name + "|" +
  normalized_position_class
)
```

### Indexes

- `(reporting_period_id, position_type)`
- `(reporting_period_id, requirement_status)`
- `(reporting_period_id, position_class)`
- `(reporting_period_id, vacancy_count)`

---

## 10. Table `position_projection_values`

Daripada membuat kolom baru setiap tahun, simpan proyeksi secara vertikal.

### Columns

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| position_requirement_snapshot_id | BIGINT UNSIGNED | FK |
| metric_type | VARCHAR(40) | `RETIREMENT`, `REQUIREMENT` |
| projection_year | SMALLINT UNSIGNED | contoh 2026 |
| value | INT NULL | nilai |
| created_at | TIMESTAMP | optional |
| updated_at | TIMESTAMP | optional |

### Unique

- UNIQUE `(position_requirement_snapshot_id, metric_type, projection_year)`

### Contoh

```text
position_snapshot=42, RETIREMENT, 2026, 1
position_snapshot=42, RETIREMENT, 2027, 0
position_snapshot=42, REQUIREMENT, 2026, 3
position_snapshot=42, REQUIREMENT, 2027, 4
```

Keuntungan: file 2027 yang menambah `Pensiun 2033` tidak membutuhkan migration.

---

## 11. Table `import_batches`

Audit satu upload.

### Columns

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| reporting_period_id | BIGINT UNSIGNED | FK |
| uploaded_by | BIGINT UNSIGNED | FK users |
| source_type | VARCHAR(40) | `PERSONNEL_DUK`, `POSITION_REQUIREMENT` |
| original_filename | VARCHAR(255) | |
| sha256 | CHAR(64) | checksum |
| disk | VARCHAR(50) | private disk |
| path | VARCHAR(500) | private path |
| status | VARCHAR(30) | uploaded/parsing/validated/committed/failed |
| total_rows | INT UNSIGNED | |
| valid_rows | INT UNSIGNED | |
| warning_rows | INT UNSIGNED | |
| error_rows | INT UNSIGNED | |
| summary | JSON NULL | diff/import preview |
| committed_at | TIMESTAMP NULL | |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

### Recommended unique/index

Index:

- `(reporting_period_id, source_type, status)`
- `(sha256)`

Application-level duplicate check:

```text
period + source_type + sha256
```

Tidak perlu selalu UNIQUE secara DB bila organisasi ingin menyimpan percobaan import ulang identik sebagai histori.

---

## 12. Table `import_issues`

Menyimpan error/warning hasil parser.

### Columns

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED |
| import_batch_id | BIGINT UNSIGNED |
| source_row | INT NULL |
| severity | VARCHAR(20) |
| field_name | VARCHAR(100) NULL |
| code | VARCHAR(100) |
| message | TEXT |
| row_payload | JSON NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

`severity`:

- `ERROR`
- `WARNING`
- `INFO`

Contoh code:

- `DUPLICATE_NIP`
- `MISSING_STATUS`
- `UNKNOWN_STATUS`
- `INVALID_POSITION_CLASS`
- `UNMATCHED_SUPPLEMENTAL_PERSON`
- `HEADER_NOT_FOUND`

---

## 13. Optional Table `person_aliases`

Hanya tambahkan jika mismatch nama berulang menjadi masalah nyata.

| Column | Type |
|---|---|
| id | BIGINT |
| person_id | BIGINT |
| alias_name | VARCHAR(255) |
| normalized_alias | VARCHAR(255) |
| source | VARCHAR(50) |
| timestamps | |

Tidak wajib pada MVP.

---

## 14. Snapshot Replace Algorithm

### Personnel

Pseudo-flow:

```text
BEGIN

1. Lock reporting_period.
2. Parse file.
3. Validate all rows.
4. Abort jika blocking error > 0.
5. Resolve/create people master.
6. DELETE personnel_snapshots
   WHERE reporting_period_id = :period_id
     AND source scope = personnel DUK;
7. Bulk INSERT seluruh snapshot valid.
8. Mark import_batch committed.
9. Update period readiness.

COMMIT
```

Karena `personnel_snapshots` hanya mempunyai canonical source DUK, delete dapat dilakukan berdasarkan `reporting_period_id`. Jika kelak banyak personnel source dipisahkan, tambahkan `source_type` atau `dataset_key` pada snapshot.

### Position Requirement

```text
BEGIN

1. Lock reporting_period.
2. Parse + validate.
3. DELETE position_projection_values
   melalui cascade dari snapshot lama.
4. DELETE position_requirement_snapshots
   WHERE reporting_period_id = :period_id;
5. INSERT snapshot baru.
6. INSERT projection values.
7. Mark import committed.

COMMIT
```

### Kenapa bukan update satu per satu?

Full replace memastikan row yang sudah hilang dari file sumber juga hilang dari snapshot periode tersebut. Upsert saja akan meninggalkan stale rows.

---

## 15. Delete/Cascade Rules

Recommended:

```text
reporting_periods
  -> personnel_snapshots             CASCADE
  -> position_requirement_snapshots  CASCADE
  -> import_batches                  RESTRICT/controlled

people
  -> personnel_snapshots             RESTRICT

position_requirement_snapshots
  -> position_projection_values      CASCADE

import_batches
  -> import_issues                    CASCADE
```

Untuk audit, jangan otomatis delete `import_batches` saat melakukan re-import. Batch lama tetap disimpan sebagai history.

---

## 16. Period Publication

Recommended business rule:

```text
draft
  -> source 1 valid
  -> source 2 valid
  -> ready
  -> published
```

Public queries hanya membaca period `published`.

Saat September direvisi:

1. data baru divalidasi;
2. snapshot September diganti di transaction;
3. periode tetap/menjadi published setelah commit berhasil;
4. dashboard cache diinvalidasi.

Jika ingin zero-risk publication, gunakan status `draft revision` dan switch batch version saat publish. MVP dapat dimulai dengan transaction + backup karena dataset kecil.

---

## 17. Query Examples

### Total ASN

```sql
SELECT COUNT(*) AS total_asn
FROM personnel_snapshots ps
JOIN reporting_periods rp
  ON rp.id = ps.reporting_period_id
WHERE rp.period_month = '2026-09-01'
  AND ps.employment_group = 'ASN';
```

### ASN per status

```sql
SELECT employment_status, COUNT(*) AS total
FROM personnel_snapshots
WHERE reporting_period_id = :period_id
  AND employment_group = 'ASN'
GROUP BY employment_status
ORDER BY total DESC;
```

### Pendidikan

```sql
SELECT education_level, COUNT(*) AS total
FROM personnel_snapshots
WHERE reporting_period_id = :period_id
  AND employment_group = :group
GROUP BY education_level
ORDER BY total DESC;
```

### Kebutuhan vs pemangku

```sql
SELECT
    position_name,
    SUM(incumbent_count) AS incumbent_count,
    SUM(requirement_count) AS requirement_count,
    SUM(vacancy_count) AS vacancy_count
FROM position_requirement_snapshots
WHERE reporting_period_id = :period_id
GROUP BY position_name
ORDER BY vacancy_count DESC;
```

### Proyeksi pensiun

```sql
SELECT
    ppv.projection_year,
    SUM(ppv.value) AS total
FROM position_projection_values ppv
JOIN position_requirement_snapshots prs
  ON prs.id = ppv.position_requirement_snapshot_id
WHERE prs.reporting_period_id = :period_id
  AND ppv.metric_type = 'RETIREMENT'
GROUP BY ppv.projection_year
ORDER BY ppv.projection_year;
```

---

## 18. Dashboard Query Layer

Jangan taruh aggregate query langsung di Filament widget atau Livewire component.

Recommended:

```text
PersonnelDashboardQuery
- totals(period)
- statusBreakdown(period)
- genderBreakdown(period, group)
- educationBreakdown(period, group)
- gradeBreakdown(period)
- positionBreakdown(period, group)
- positionClassBreakdown(period)
- placementBreakdown(period, group)

PositionRequirementDashboardQuery
- totals(period)
- requirementStatusBreakdown(period)
- topVacancies(period)
- needsVsIncumbents(period)
- retirementProjection(period)
- requirementProjection(period)
```

Public dashboard dan admin widget dapat memakai query service yang sama.

---

## 19. Cache

Dataset awal kecil, sehingga cache bukan requirement wajib, tetapi struktur aplikasi sebaiknya siap.

Suggested keys:

```text
dashboard:{period_id}:personnel:summary
dashboard:{period_id}:personnel:education:{group}
dashboard:{period_id}:position:summary
```

Invalidate seluruh key periode setelah import commit atau publish.

Redis optional. File/database cache Laravel cukup untuk awal bila traffic rendah.

---

## 20. Import Parser Design

Recommended classes:

```text
PersonnelWorkbookReader
PersonnelRowMapper
PersonnelNormalizer
PersonnelValidator
PersonnelDiffService
PersonnelSnapshotReplacer

PositionRequirementWorkbookReader
PositionRequirementRowMapper
PositionRequirementValidator
PositionProjectionParser
PositionRequirementSnapshotReplacer
```

### Dynamic projection regex

Contoh:

```text
^Pensiun\s+(\d{4})$
^Proyeksi\s+Kebutuhan\s+(\d{4})$
```

Tahun hasil capture masuk `projection_year`.

---

## 21. Supplemental `P3K - PPNPN`

Recommended rule:

1. `DUK PEGAWAI` menentukan populasi aktif.
2. Supplemental hanya memperkaya record yang sudah ada.
3. Match dengan NIP bila tersedia.
4. Jika NIP tidak ada, gunakan normalized name matching yang lebih konservatif.
5. Unmatched supplemental row → warning.
6. Unmatched row **tidak otomatis membuat person baru**.

Ini penting karena jumlah record supplemental pada file September tidak identik dengan population sheet utama.

---

## 22. Diff Preview

Sebelum replace, sistem membandingkan incoming snapshot dengan snapshot periode saat ini.

Summary JSON pada `import_batches.summary` dapat berbentuk:

```json
{
  "incoming": 161,
  "valid": 160,
  "warnings": 1,
  "errors": 0,
  "new": 2,
  "changed": 11,
  "unchanged": 147,
  "removed": 1
}
```

Angka di atas hanyalah contoh format, bukan hasil aktual.

Field-level diff tidak harus disimpan permanen pada MVP; dapat dihitung saat preview.

---

## 23. Concurrency

Untuk mencegah dua admin commit periode/source yang sama secara bersamaan:

- lock row `reporting_periods` dengan `SELECT ... FOR UPDATE`; atau
- gunakan Laravel atomic lock jika cache store mendukungnya.

Hanya satu `SnapshotReplacer` boleh commit untuk satu period pada satu waktu.

---

## 24. Public Data Boundary

Jangan buat endpoint publik seperti:

```text
/api/personnel
/api/personnel/{id}
```

Public endpoint hanya agregat, misalnya:

```text
GET /dashboard
GET /dashboard/data?period=2026-09&section=education&group=ASN
```

Response agregat:

```json
[
  {"label": "S1", "value": 62},
  {"label": "S2", "value": 57}
]
```

Tidak ada:

- `nip`
- `name`
- `person_id`

---

## 25. Export

Export query membaca snapshot berdasarkan filter.

Untuk XLSX:

- streaming/chunk query jika data membesar;
- sheet title mencantumkan period;
- header terstandardisasi;
- export metadata period dan generated_at;
- job/queue optional jika data > 10k–50k rows.

Untuk ukuran saat ini, synchronous export masih cukup.

---

## 26. Migration Order

Recommended:

1. default Laravel users tables;
2. roles/permissions package tables;
3. `reporting_periods`;
4. `people`;
5. `import_batches`;
6. `import_issues`;
7. `personnel_snapshots`;
8. `position_requirement_snapshots`;
9. `position_projection_values`;
10. optional `person_aliases`.

Perhatikan circular reference `published_by` dan `uploaded_by`; migration dapat dibuat setelah users table tersedia.

---

## 27. Suggested Eloquent Models

```text
User
ReportingPeriod
Person
PersonnelSnapshot
ImportBatch
ImportIssue
PositionRequirementSnapshot
PositionProjectionValue
```

Relationships:

```text
ReportingPeriod hasMany PersonnelSnapshot
ReportingPeriod hasMany PositionRequirementSnapshot
ReportingPeriod hasMany ImportBatch

Person hasMany PersonnelSnapshot
PersonnelSnapshot belongsTo Person
PersonnelSnapshot belongsTo ReportingPeriod
PersonnelSnapshot belongsTo ImportBatch

PositionRequirementSnapshot belongsTo ReportingPeriod
PositionRequirementSnapshot belongsTo ImportBatch
PositionRequirementSnapshot hasMany PositionProjectionValue

ImportBatch hasMany ImportIssue
```

---

## 28. Data Retention

Recommended:

- published snapshots: simpan tanpa batas selama masih dibutuhkan untuk histori;
- import batch metadata: simpan minimal 2–5 tahun;
- raw upload file: tentukan policy internal, contoh 12–24 bulan;
- logs: 30–90 hari;
- backup DB: harian + retention mingguan/bulanan sesuai kebijakan organisasi.

Jangan menghapus histori bulanan hanya karena bulan baru telah diimport.

---

## 29. Backup dan Recovery

Minimum:

- automated MySQL backup harian;
- encrypt backup bila berisi data kepegawaian;
- uji restore berkala;
- source file upload private;
- jangan mengandalkan file Excel sebagai satu-satunya backup database.

---

## 30. Final Recommendation

Untuk requirement saat ini, schema yang paling aman dan sederhana adalah:

```text
1 Laravel application
1 MySQL database
1 Filament Admin Panel
1 public dashboard
monthly snapshot data
transactional full replacement
audit import
dynamic yearly projections
```

Arsitektur ini menyelesaikan kebutuhan overwrite otomatis tanpa kehilangan histori, menjaga data publik tetap agregat, dan tetap mudah dikembangkan bila jumlah data, periode, atau jenis proyeksi bertambah.
