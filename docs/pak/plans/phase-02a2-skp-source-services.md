# Phase 02A.2 — SKP Source Application Foundation

**Status:** implementation plan dengan corrective review; dokumentasi saja. Implementasi 02A.2 belum dimulai.

## 1. Objective

Membangun application/domain foundation untuk menerima, memvalidasi, menyimpan, dan mengoreksi sumber SKP di atas schema Phase 02A.1, tanpa source-selection atau perhitungan AK.

Keputusan yang dikonfirmasi untuk checkpoint ini:

- Commit evaluation atomik per batch.
- Employee matching menggunakan exact NIP ke `people.nip`.
- Registrasi additive kelima permission Phase 02 termasuk checkpoint ini.
- Setiap normalized record yang eligible untuk fingerprint/READY/import wajib memiliki stable, nonblank `source_record_identifier`.

Authoritative references: parent plan `phase-02-skp-conversion.md`, `phase-02a1-schema-models.md`, `../business-rules.md`, `../decisions.md`, `../test-cases.md`, serta migrations dan models aktual. Keputusan client dan pending business rules tidak diubah.

## 2. Current Repository Baseline

- Branch audit: `feature/pak-phase-02`; HEAD `1a665e1`.
- Commit terkait: `bf5b014` untuk portability timestamp MariaDB 10.5 dan `2ff63b9` untuk plan schema/model.
- Working tree bersih sebelum penambahan dokumen ini.
- Laravel 12, PHP 8.2+, PHPUnit 11; PhpSpreadsheet sudah tersedia.
- Tiga tabel, tiga model, dan enam enum Phase 02A.1 sudah tersedia.
- Model menggunakan Eloquent guards; `PakModel` melarang delete.
- Service existing menggunakan `DB::transaction()`, domain authorization, `ValidationException`, dan audit.
- `PakAuditService` belum menerima subject model SKP. Payload whitelist existing adalah `before`, `after`, `reason`, `supersedes_id`.
- `PakPermissionSeeder` belum mendaftarkan permission Phase 02.
- Disk `local` menggunakan `storage/app/private`, `serve=false`, dan `throw=false`.
- Tests existing mencakup schema, model guards, migration lifecycle, serta concurrency constraints melalui dua proses.

Audit planning ini tidak menjalankan ulang regression suite dan tidak mengklaim hasil test implementasi baru.

## 3. In Scope

- Typed DTO untuk source occurrence dan normalized domain record.
- Canonical serialization dan source/content fingerprints.
- Adapter contract yang independen dari workbook tertentu.
- Person matching, source validation, preparation, dan classification.
- Commit batch atomik dan idempotent.
- Explicit correction/versioning.
- Private source-file lifecycle, compensation, dan reconciliation.
- Authorization, audit, error translation, serta automated domain/integration/concurrency tests.
- Registrasi additive permission sesuai parent plan.

Tidak membutuhkan tabel atau migration baru.

## 4. Explicitly Out of Scope

- Predicate/coefficient rule implementation, formula AK, monthly segmentation, conversion periods, dan source-selection.
- `pak_conversion_runs`, `pak_conversion_periods`, serta automatic rule-set effective-date resolver.
- BR-SKP-008 recalculation workflow.
- Filament, upload/history/correction UI, portal, dan document generation.
- Akumulasi, Penetapan, KP/KJ, TTE, dan numbering.
- Identity-changing correction.
- Input aktif PROVISIONAL atau CUSTOM_PERIOD.
- Production client adapter yang belum diverifikasi.
- Additional attachment metadata yang membutuhkan perubahan schema.

## 5. Dependencies from Phase 02A.1

Pertahankan schema dan model contracts:

- Domain evaluation wajib source/content fingerprint lowercase hex 64 karakter.
- CURRENT projection menyalin source identity; SUPERSEDED projection NULL.
- Nullable unique current identity dan `previous_version_id`; historical source fingerprint indexed non-unique.
- Ordinary correction mempertahankan enam identity fields.
- Batch COMMITTED tertutup dan rows immutable; tidak boleh ada PENDING/READY saat closure.
- IMPORTED/DUPLICATE wajib evaluation link.
- RESTRICT FK dan canonical identity `people.id`.

Seluruh domain writes melalui model events. Tidak menggunakan bulk update atau mengubah `PakModel` global. Model guards bukan pengganti transaksi/locking.

Schema/model 02A.1 tetap mengizinkan nullable `source_record_identifier`; eligibility service 02A.2 lebih ketat sesuai corrective decision terbaru. Tidak mengubah schema atau mengisi identifier sintetis untuk data historis. Existing evaluation tanpa identifier stabil tidak otomatis dimigrasikan atau diterima sebagai input eligible; lihat §20.

## 6. Domain Contracts

### 6.1 Normalized SKP Record

Pisahkan dua readonly typed DTO:

| Representasi | Isi |
|---|---|
| `SkpSourceRowData` | NIP sumber, field SKP, lokasi occurrence, raw provenance minimum, source-structure evidence, dan validation issues adapter |
| `NormalizedSkpRecord` | Canonical `person_id`, seluruh identity/content fields, enum existing, dan tanggal tervalidasi |

Identity fields, dalam urutan kontrak:

1. `person_id`
2. `source_kind`
3. `performance_year`
4. `evaluation_type`
5. `source_reference`
6. `source_record_identifier`

Content fields tambahan, dalam urutan kontrak:

1. `predicate_code`
2. `predicate_label_original`
3. `coverage_start`
4. `coverage_end`
5. `source_date`
6. `source_position_name`
7. `source_functional_level_label`
8. `source_position_tmt`
9. `source_sk_number`
10. `source_sk_date`

Actor, revision reason, history link, batch, dan lokasi workbook bukan content fingerprint.

Validasi panjang/nullability mengikuti schema, dengan eligibility tambahan stable nonblank source record identifier. Official reference wajib nonblank. Predicate code disimpan sebagai source-domain identifier; tidak melakukan lookup faktor atau membuat mapping predikat rekaan. Person tidak dibuat/diubah dari workbook.

Coverage start harus ≤ end dan kedua tanggal berada pada `performance_year`. Ini **bukan rule bisnis baru**: sudah eksplisit pada `phase-02a1-schema-models.md`, bagian PerformanceEvaluation invariants, dan enforced oleh `app/Models/PerformanceEvaluation.php` melalui pemeriksaan tahun `coverage_start`/`coverage_end`. Coverage tidak diubah menjadi conversion applied range.

Raw/original values dipertahankan pada provenance row. Nilai canonical digunakan untuk domain persistence dan fingerprint.

### 6.2 Canonical Serialization

`SkpFingerprintSerializer` menjadi satu-satunya implementation path canonicalization dan hashing.

- JSON UTF-8 tanpa pretty-print; fixed keys/fixed ordering.
- `JSON_UNESCAPED_UNICODE`, `JSON_UNESCAPED_SLASHES`, `JSON_THROW_ON_ERROR`.
- Null eksplisit; field tidak dihilangkan; null berbeda dari empty string.
- `person_id` dan `performance_year` integer; source identifiers tetap string.
- Tanggal valid menggunakan `YYYY-MM-DD`; tanggal invalid ditolak, tidak diperbaiki otomatis.
- Invalid UTF-8 ditolak; tidak melakukan Unicode case folding atau normalisasi Unicode tambahan.
- Identifier di-trim pada tepi, preserve kapitalisasi/leading zero; whitespace internal tidak di-collapse.
- Descriptive text di-trim dan rangkaian whitespace di-collapse menjadi satu ASCII space.
- Whitespace didefinisikan eksplisit sebagai Unicode separator serta tab/newline/carriage-return/form-feed/vertical-tab.
- String kosong hasil normalization tetap empty string; field validation menentukan boleh/tidaknya. Khusus source record identifier, null/empty/blank tidak eligible untuk fingerprint.
- `predicate_code` dan `source_sk_number` diperlakukan sebagai identifier; label/nama jabatan/jenjang sebagai descriptive text.

Serializer menyediakan canonical payload dan hash agar service membandingkan payload ketika hash cocok. Tidak menerima input invalid/ambiguous sebagai valid fingerprintable domain record.

### 6.3 Source Identity Fingerprint

Payload berurutan: `schema_version=skp-source-v1`, kemudian enam identity fields pada §6.1. Output SHA-256 lowercase hexadecimal 64 karakter.

**Setiap normalized record yang eligible untuk READY, import, atau fingerprint wajib memiliki `source_record_identifier` stable dan nonblank.** Adapter/source harus membuktikan identifier tersebut dari struktur sumber; nilai nonblank saja tidak membuktikan stabilitas.

Jika tidak dapat dibuktikan, occurrence menjadi `IDENTITY_AMBIGUOUS`. Jangan menghasilkan valid source identity fingerprint dengan identifier NULL, kosong, atau blank. Source/content fingerprint row tetap NULL karena canonical identity belum valid. Raw provenance tetap dipertahankan.

Jangan membentuk identifier dari Excel row/order, database ID, UUID, predicate, filename, batch ID, import order, atau content hash. `person_id` tetap canonical employee identity, bukan pengganti identifier record sumber.

### 6.4 Content Fingerprint

Payload berurutan:

1. `schema_version=skp-content-v1`.
2. `source_schema_version=skp-source-v1`.
3. Enam identity fields.
4. Sepuluh content fields tambahan menurut §6.1.

Output SHA-256 lowercase hexadecimal 64 karakter. Current fingerprint bukan hash ketiga: salin source fingerprint untuk CURRENT, NULL untuk SUPERSEDED.

Perubahan canonical identity field mengubah **source identity fingerprint dan content fingerprint**. Perubahan canonical content-only field **tidak mengubah source identity fingerprint tetapi harus mengubah content fingerprint**. Perubahan kosmetik yang menghasilkan canonical value sama tidak dianggap perubahan content.

Batch, file checksum, actor/import time, revision reason, dan internal history link tidak memengaruhi fingerprint.

### 6.5 Duplicate / Conflict / Ambiguity

| Kondisi | Hasil |
|---|---|
| Identity dan canonical content sama dengan CURRENT | DUPLICATE; referensikan evaluation existing |
| Identity sama, canonical content berbeda | CONFLICT; issue `SOURCE_CONTENT_CONFLICT`; tanpa evaluation baru |
| Identity berbeda, valid, dan stable identifier terbukti | Kandidat evaluation baru |
| Stable identifier atau kesamaan logical source record tidak dapat dibuktikan | IDENTITY_AMBIGUOUS |
| Hash cocok tetapi canonical payload berbeda | Integrity failure; jangan nyatakan duplicate |

CONFLICT selalu `performance_evaluation_id=NULL` pada service 02A.2. Classification terhadap CURRENT, bukan revision historis. Reimport predecessor setelah correction menjadi conflict bila berbeda dari successor.

**Duplicate intra-batch:** jika source structure membuktikan beberapa occurrence berasal dari record sumber yang sama, identity sama, dan canonical content sama:

- Kelompokkan occurrence berdasarkan valid canonical source identity dan kesetaraan content, setelah bukti source structure tervalidasi.
- Jika CURRENT matching sudah ada, seluruh occurrence menjadi DUPLICATE dan menunjuk evaluation tersebut.
- Jika belum ada CURRENT, pilih row dengan `record_ordinal` terkecil sebagai representative yang membuat evaluation dan berstatus IMPORTED. Unique `(batch_id, record_ordinal)` membuat pemilihan tidak ambigu.
- Occurrence equivalent lainnya menjadi DUPLICATE dengan `performance_evaluation_id` yang sama.
- Representative hanya menentukan provenance outcome, bukan identity/content/hash atau pilihan sumber conversion. Perubahan urutan occurrence boleh mengubah representative, tetapi tidak domain evaluation content/fingerprint.
- Jika concurrent import lebih dahulu membuat CURRENT yang identik, classification ulang menjadikan seluruh kelompok DUPLICATE.
- Jika identity group memiliki canonical content berbeda, kelompok menjadi CONFLICT; tidak memilih pemenang menurut ordinal.
- Jika tidak terbukti sebagai occurrence dari logical source record yang sama, tetap IDENTITY_AMBIGUOUS. Jangan menganggap kemiripan content atau identifier nonblank sebagai bukti yang cukup.

### 6.6 Correction / Versioning

Correction merupakan operation eksplisit:

- Lock predecessor dan pastikan masih CURRENT.
- Validasi enam identity fields dan source fingerprint sama; eligibility identifier tetap berlaku.
- Hitung content canonical di server. Content sama menghasilkan `NO_CHANGES` sebelum lifecycle mutation.
- Revision reason wajib nonblank; reason saja tidak menciptakan revision.
- Supersede predecessor lalu buat successor CURRENT.
- Simpan actor/time, predecessor link, dan audit dalam transaksi yang sama.

Identity-changing correction ditolak dan deferred. Initial evaluation tidak memiliki revision reason. `historical_reason` tetap terpisah.

## 7. Adapter Architecture

`SkpWorkbookAdapter` menyediakan adapter key/version dan normalized schema version; membaca stream/local temporary input dari storage layer; menghasilkan iterable `SkpSourceRowData`.

Adapter mempertahankan source locator/raw provenance minimum dan bukti stable identifier serta repeated occurrence dari struktur sumber. Malformed row dilaporkan tanpa menghilangkan occurrence. Core service yang menentukan eligibility dan classifications, bukan adapter.

Adapter tidak mengakses evaluation database, membentuk fingerprint, menentukan duplicate/conflict semantics, melakukan correction/versioning, atau menghitung AK.

02A.2 menggunakan synthetic adapter untuk automated tests. Production client adapter baru accepted setelah sanitized representative workbook dengan struktur nyata diverifikasi. Struktur Excel dan stable identifier tidak boleh ditebak.

## 8. Import Preparation Pipeline

`SkpImportService::prepare(...)`:

1. Authorize active actor dengan `pak.access` dan `pak.skp.manage`.
2. Validasi adapter serta private disk yang dipilih eksplisit.
3. Store file pada object key baru; hitung dan verifikasi checksum.
4. Simpan batch PARSING dalam transaksi metadata.
5. Parse source di luar transaksi domain panjang.
6. Simpan setiap occurrence sebagai provenance row; invalid occurrence retained.
7. Resolve exact NIP setelah trim ke `people.nip`.
8. Validasi source record, stable nonblank identifier, source-structure evidence, dan intra-batch groups.
9. Bentuk normalized DTO serta fingerprints hanya setelah identity eligibility lolos.
10. Classify terhadap CURRENT existing; kandidat baru tetap READY sampai commit. Equivalent occurrence yang belum memiliki persisted evaluation tidak ditandai DUPLICATE prematur karena model mewajibkan evaluation link.
11. Finalisasi preparation menjadi READY, INVALID, atau FAILED.

Person resolution: kosong/invalid → INVALID; tidak ditemukan → NOT_FOUND; tepat satu exact candidate → MATCHED; lebih dari satu → AMBIGUOUS. Tidak memakai nama, fuzzy matching, fallback snapshot, atau menciptakan Person. Identifier NIP preserve leading zero.

READY batch berarti parsing selesai dan outcomes dapat difinalisasi, bukan semua row valid. Structural workbook failure → INVALID; technical failure → FAILED. Semua create/update row mengunci batch dan memastikan belum ditutup.

## 9. Import Commit Pipeline

`SkpImportService::commit(User $actor, int $batchId)`:

1. Authorize ulang. Untuk batch COMMITTED, kembalikan hasil persisted secara idempotent tanpa file operation/mutation/audit baru; status tetap diperiksa ulang di dalam transaksi bila diperlukan.
2. Untuk commit baru, verifikasi file tersedia dan checksum cocok.
3. Mulai transaksi atomik per batch; lock batch `lockForUpdate()`.
4. COMMITTED mengembalikan hasil persisted; status selain READY ditolak.
5. Muat persisted rows; client classification/fingerprint bukan authoritative.
6. Rekonstruksi DTO, validasi ulang matching, stable identity, canonical payload, dan identity groups.
7. Reclassify terhadap CURRENT pada saat commit. Terapkan representative/equivalent occurrence rules §6.5.
8. Delegasikan evaluation creation ke `PerformanceEvaluationService`.
9. Simpan outcomes IMPORTED/DUPLICATE/CONFLICT dan terminal outcomes lainnya.
10. Pastikan tidak tersisa PENDING/READY; simpan COMMITTED, actor/time, summary, dan audit; commit.

Technical failure me-rollback seluruh evaluation, outcome mutation, audit, dan closure transaksi tersebut. Catat failure provenance setelah rollback dalam transaksi terpisah, dengan lock/recheck batch agar tidak menimpa COMMITTED hasil request lain. File/batch yang sudah memiliki referensi tetap retained.

Retry batch FAILED dilakukan sebagai import operation baru; raw provenance batch lama tidak diedit. Collision retry internal dijelaskan pada §12.

## 10. Correction Pipeline

`PerformanceEvaluationService::correct(...)` menerima actor, predecessor ID, proposed normalized content, revision reason, dan persisted source-row provenance reference.

1. Authorize, lalu mulai transaksi.
2. Lock predecessor berdasarkan ID; pastikan CURRENT.
3. Validasi identity equality dan stable nonblank source identifier.
4. Hitung canonical content; tolak `NO_CHANGES` sebelum mutation.
5. Validasi optional history link milik person yang sama.
6. Update predecessor menjadi SUPERSEDED, projection NULL, actor/time terisi.
7. Create successor CURRENT dengan `previous_version_id=predecessor.id` dan current projection sama dengan source fingerprint.
8. Simpan correction audit melalui contract existing; commit.

Audit memakai top-level **hanya** `before`, `after`, `reason`, `supersedes_id`. Contoh struktur aman: `before` berisi predecessor ID/status; `after` berisi successor ID/status, source reference dan source-row ID minimum bila diperlukan; `reason` berisi alasan; `supersedes_id` menunjuk predecessor. Actor/time tersedia pada event existing. Tidak menambah top-level source/provenance key atau memperluas whitelist payload.

Nested payload dibangun melalui explicit allowlist, bukan `getAttributes()`/`toArray()` seluruh model. Tidak menyalin raw payload/storage path/NIP. Dukungan subject type SKP merupakan perubahan terpisah dari payload whitelist.

Raw correction evidence berasal dari persisted source row tervalidasi. Audit menunjuk row tersebut tanpa mengubah committed row; conflict row tidak diubah retroaktif menjadi IMPORTED. Failure successor/audit memulihkan predecessor. Concurrent stale correction ditolak, bukan dialihkan ke successor terbaru.

## 11. Batch and Row State Transitions

| Entity | Transisi service |
|---|---|
| Batch | PARSING → READY / INVALID / FAILED |
| Batch | READY → COMMITTED |
| Batch | READY → FAILED setelah technical failure yang telah rollback |
| COMMITTED batch | Terminal dan immutable |
| PENDING row | READY / DUPLICATE / CONFLICT / IDENTITY_AMBIGUOUS / INVALID / FAILED |
| Kandidat valid saat commit | IMPORTED / DUPLICATE / CONFLICT berdasarkan pemeriksaan ulang |
| Evaluation | CURRENT → SUPERSEDED hanya melalui correction |

Person failure menjadi INVALID row dengan resolution status/issue spesifik. Source identity ambiguity menjadi IDENTITY_AMBIGUOUS. COMMITTED boleh mixed terminal outcomes, tanpa PENDING/READY dan tanpa row baru setelah closure.

## 12. Transactions and Concurrency

- Preparation provenance memiliki transaksi tersendiri, terpisah dari domain commit.
- Batch lock menserialisasi commit ganda dan row creation versus closure.
- Existing CURRENT dikunci saat dibandingkan dengan import; correction mengunci predecessor.
- Tidak memakai global lock atau mengunci seluruh tabel Person.
- Proses identity dalam urutan fingerprint konsisten untuk mengurangi deadlock; representative ordinal tidak menentukan urutan domain lock.
- Unique `pe_current_source_uq` menjadi final arbiter untuk concurrent initial import.
- Collision current identity menyebabkan rollback transaksi batch lalu retry/re-query dalam transaksi baru. Klasifikasikan duplicate/conflict terhadap CURRENT, bukan generic failure.
- Hanya translate relevant unique collision; FK/connection/database errors lain tidak disamarkan.
- Maksimum tiga attempts untuk retryable identity collision/deadlock; sesudahnya concurrency failure tersanitasi.
- Unique predecessor/current projection tetap melindungi correction. Retry tidak mengalihkan predecessor target.
- Filesystem operations tidak dimasukkan ke transaction retry.

## 13. Private Source Storage Lifecycle

`SkpSourceStorage` mengelola explicit private disk selection, stream checksum SHA-256, object placement, write/read verification, compensation, dan reconciliation.

Sequence: stage incoming stream secara private → random object key tanpa identitas pegawai → store dan verify checksum → persist batch dengan key final → file menjadi retained provenance.

Disk existing `throw=false`: periksa failure return secara eksplisit, jangan menganggap tidak ada exception berarti sukses. Tidak menggunakan public disk, URL publik, atau DB blob.

Compensation hanya menghapus object baru milik operasi gagal yang dipastikan belum memiliki committed DB reference. Jangan menghapus reused object, failed-batch evidence, atau historical evidence.

Jika cleanup gagal, pertahankan private recovery receipt berisi operation/key/checksum dan safe error code minimum. Reconciliation memeriksa referensi database kembali sebelum penghapusan. Jika commit outcome belum pasti, rekonsiliasi dahulu; jangan menganggap rollback. Recovery receipt/storage reference tidak masuk business audit.

Reconciliation berupa backend operation eksplisit; tidak menambahkan scheduler atau historical purge.

## 14. Error Handling

Gunakan `ValidationException` existing untuk domain rejection dan structured issue codes pada result/provenance.

| Kategori | Codes |
|---|---|
| Source validation | `SOURCE_VALIDATION_FAILED` |
| Person | `PERSON_NOT_FOUND`, `PERSON_AMBIGUOUS`, `PERSON_IDENTIFIER_INVALID` |
| Identity | `SOURCE_IDENTITY_AMBIGUOUS` |
| Conflict | `SOURCE_CONTENT_CONFLICT` |
| Correction | `NO_CHANGES`, `SOURCE_IDENTITY_CHANGE_NOT_ALLOWED`, `PREDECESSOR_NOT_CURRENT` |
| Storage | `SOURCE_STORAGE_FAILED`, `SOURCE_CHECKSUM_MISMATCH` |
| Persistence | `PERSISTENCE_FAILED` |
| Concurrency | `CONCURRENCY_RETRY_EXHAUSTED` |
| Integrity | `FINGERPRINT_PAYLOAD_MISMATCH` |

DUPLICATE adalah normal outcome, bukan exception. Audit menyimpan business metadata minimum dalam contract existing, tanpa complete employee snapshot/raw row/NIP/credential/binary/disk/object key/SQL exception.

## 15. Proposed Classes and Files

Di bawah `app/Domain/Pak/`:

| Proposed class/file | Responsibility |
|---|---|
| `DTOs/SkpSourceRowData.php` | Source occurrence/provenance dan structural evidence |
| `DTOs/NormalizedSkpRecord.php` | Typed eligible domain input |
| `DTOs/SkpImportResult.php` | Batch ID/status/counts/issue summary |
| `Contracts/SkpWorkbookAdapter.php` | Adapter boundary |
| `Services/SkpFingerprintSerializer.php` | Canonical payload dan hashes |
| `Services/SkpPersonMatcher.php` | Exact NIP matching |
| `Services/SkpImportService.php` | Prepare, classify/group, commit, provenance |
| `Services/PerformanceEvaluationService.php` | Creation dan explicit correction |
| `Services/SkpSourceStorage.php` | Private file lifecycle/reconciliation |

Integrasi existing yang nanti diubah:

- `app/Domain/Pak/Services/PakAuditService.php`: tambahkan subject SKP yang diperlukan; **payload whitelist tidak diperluas**.
- `database/seeders/PakPermissionSeeder.php`: additive/idempotent registration dan baseline grants.
- `phpunit.skp-mysql.xml`: sertakan service/concurrency tests dengan disposable database safeguards existing.

Exact permission names ditemukan pada parent plan, bagian **Baseline permission seeder eksplisit**:

1. `pak.skp.view`
2. `pak.skp.manage`
3. `pak.skp.provisional`
4. `pak.conversion.view`
5. `pak.conversion.calculate`

Super Admin dan Admin PAK memperoleh kelimanya secara additive termasuk existing roles. Admin SDM/Pegawai tidak mendapat otomatis; custom permissions tidak dikurangi; jangan reset existing role dengan `syncPermissions()`. Tidak mengubah user assignment atau menambah global bypass. Permission conversion didaftarkan tanpa conversion implementation.

Reuse `PakEmployeeAuthorization`: write membutuhkan active actor, `pak.access`, `pak.skp.manage`; read hasil/provenance membutuhkan `pak.skp.view` dan dependency read terkait. Permission provisional tidak membuka input flow baru. Tidak membuat policy/UI baru, mengubah schema/enums existing, atau `PakModel`.

Proposed test files: `tests/Unit/Pak/SkpFingerprintSerializerTest.php`, `tests/Feature/Pak/SkpImportServiceTest.php`, `PerformanceEvaluationServiceTest.php`, `SkpSourceStorageTest.php`, `SkpSourceConcurrencyTest.php`, dan `SkpSourceAuthorizationTest.php` pada direktori feature PAK; synthetic adapter/fixtures di `tests/Support/Pak/`.

## 16. Implementation Sequence

1. Typed DTO/eligibility dan serializer dengan canonical byte-vector tests.
2. Exact person matcher, adapter contract, synthetic adapter, dan intra-batch grouping.
3. Permission baseline dan audit subject integration tanpa payload whitelist expansion.
4. Private storage, compensation, recovery receipt.
5. Preparation/persisted classifications.
6. Evaluation creation dan atomic correction.
7. Atomic commit, representative outcomes, collision translation/retry.
8. Integration/concurrency tests pada database disposable.
9. Full regression, Pint, PHP lint, dan `git diff --check`.

## 17. Test Plan

Semua cases berikut wajib menjadi executable automated tests ketika implementasi dilakukan; checkpoint ini hanya dokumentasi.

| Area | Cases minimum |
|---|---|
| Canonicalization | Fixed byte output/order, null versus empty pada nullable content fields, whitespace, UTF-8 valid/invalid, ISO dates, case/leading-zero preservation |
| Identifier eligibility | NULL/empty/blank/unproven identifier → IDENTITY_AMBIGUOUS; tidak menghasilkan valid fingerprints/evaluation |
| Fingerprint identity | Setiap perubahan canonical identity field mengubah **source identity DAN content fingerprint** |
| Fingerprint content | Setiap perubahan canonical content-only field **tidak mengubah source identity tetapi HARUS mengubah content fingerprint** |
| Canonical equivalence | Cosmetic whitespace yang canonical-equivalent tidak menghasilkan false conflict/changed hash |
| Provenance | Filename/order/batch/actor tidak memengaruhi hash; raw value retained |
| Matching | MATCHED, NOT_FOUND, INVALID; AMBIGUOUS melalui controlled candidate fixture; exact NIP tanpa Person creation |
| Coverage | End sebelum start atau tanggal di luar performance year ditolak sesuai approved model contract |
| Preparation | New source, duplicate, conflict, missing stable discriminator, malformed row, failed parsing retention |
| Intra-batch duplicate | Proven equivalent occurrences menghasilkan satu CURRENT; ordinal terkecil IMPORTED, lainnya DUPLICATE dengan evaluation sama; existing CURRENT → semua DUPLICATE |
| Intra-batch ambiguity/conflict | Unproven same-record occurrences → IDENTITY_AMBIGUOUS; same identity/different content → CONFLICT, tanpa ordinal winner |
| Representative determinism | Rerun order input query tidak mengubah representative untuk persisted ordinals; perubahan ordinal tidak mengubah domain content/hash |
| Commit | Evaluation baru, duplicate link, conflict link NULL, mixed outcomes, repeated commit idempotent |
| Closure | No PENDING/READY; concurrent row create versus commit; committed batch/rows immutable |
| Correction | Success, identity rejection, NO_CHANGES, reason-only no-op, predecessor SUPERSEDED/projection NULL, successor CURRENT/previous_version_id |
| Historical import | Predecessor payload dibandingkan dengan CURRENT successor, tidak menciptakan independent CURRENT |
| Rollback | Failure evaluation kedua/successor/audit tidak meninggalkan partial lifecycle/domain mutation |
| Concurrency | Dua imports identik/different content; dua corrections; import versus correction; commit batch ganda |
| Storage | Private disk/checksum/write failure/metadata failure cleanup/cleanup failure reconciliation/historical preservation |
| Authorization | Missing permission, inactive actor, additive/idempotent grants, custom permission preservation |
| Audit contract/privacy | Correction hanya memakai existing top-level keys; safe nested IDs/reference retained; raw payload/NIP/object key tidak tersimpan |
| Regression | Phase 01, 02A.1, SDM import, public/admin boundaries tetap lulus |

Concurrency wajib melalui service dengan proses/koneksi independen pada disposable MySQL/MariaDB. SQLite saja tidak membuktikan production locking. Gunakan fixture sintetis sebagai primary dependency; sanitized workbook acceptance tambahan.

## 18. Acceptance Criteria

1. Semua writes mengikuti service dan model contracts; tidak ada schema/migration baru.
2. Stable nonblank source identifier wajib untuk eligible records; ambiguity retained tanpa synthetic identity.
3. Canonical byte/hash vectors deterministik; identity/content change tests eksplisit lulus.
4. Ordinary import tidak mengoreksi evaluation otomatis.
5. Duplicate intra-batch menghasilkan maksimal satu CURRENT dan link equivalent rows konsisten.
6. Atomic batch commit/correction terbukti melalui failure injection dan concurrency.
7. Batch/row provenance retained; storage compensation tidak menghapus evidence existing.
8. Authorization, exact permission baseline, dan audit existing payload contract teruji.
9. Targeted/full regression lulus; production-equivalent concurrency diverifikasi.
10. Tidak ada calculator/resolver/UI/downstream PAK implementation.

## 19. Deferred Work

- Actual client adapter sampai workbook representatif/stable identifiers diverifikasi.
- Employee identifiers selain exact NIP.
- Identity-changing correction, termasuk penambahan identifier pada existing identity yang sebelumnya NULL.
- Additional attachment metadata/UI dan workflow remediation historis.
- Source-selection, predicate/coefficient rules, conversion, dan downstream workflows.
- Seluruh pending business rules tetap pending.

## 20. Open Issues / Required Decisions

| Temuan | Penanganan / status |
|---|---|
| Schema/model 02A.1 dan parent plan lama mengizinkan identifier nullable; corrective decision terbaru mewajibkan stable nonblank identifier untuk eligible source | Perketat boundary service/DTO/serializer 02A.2 tanpa migration/model edit. Tidak mengubah schema historical representation. Parent plan tidak diedit dalam checkpoint ini. |
| Jika existing operational evaluation memiliki identifier NULL/blank/unproven | Tidak eligible untuk ordinary 02A.2 correction; jangan isi identifier atau rehash diam-diam. Remediation membutuhkan approved identity-correction workflow yang deferred. Keberadaan data tersebut belum diaudit pada database operasional. |
| Model/plan 02A.1 membolehkan CONFLICT memiliki evaluation link | Service 02A.2 mengikuti keputusan terbaru: NULL. Model tidak diperketat diam-diam. |
| PRD/workflow lama masih menyebut provisional input aktif | Ikuti parent final: representasi historis saja; tidak mengedit dokumen lama atau membuka flow baru. |
| Audit subject SKP belum didukung | Tambahkan subject types terbatas saat implementasi. Existing before/after/reason/supersedes_id memadai; tidak ada kebutuhan whitelist payload expansion. Jika kebutuhan baru tidak dapat direpresentasikan aman, ajukan Open Issue sebelum mengubah whitelist. |
| Permission Phase 02 belum tersedia dalam seeder | Exact lima strings sudah ditemukan di parent plan; baseline additive disetujui masuk 02A.2. Bukan blocker keputusan. |
| Atomisitas mixed outcomes dan matching | Sudah diputuskan: atomik per batch dan exact NIP. |
| Schema belum menyediakan additional correction attachment relationship | Gunakan persisted row provenance dan nested audit reference; attachment expansion tetap deferred. |
| Client workbook/identifier proof belum diverifikasi | Blocker acceptance production client adapter, bukan synthetic foundation 02A.2. |
| Deployment-equivalent database tests belum dijalankan dalam planning | Wajib verifikasi saat implementasi; jangan klaim portability hanya dari SQLite. |

Plan siap direview untuk implementation foundation. Tidak ada schema blocker baru. Perbedaan eligibility source identifier terhadap nullable schema dicatat eksplisit sebagai pengetatan service yang diminta client, bukan perubahan historical schema. Tidak ada business rule lain yang diubah.
