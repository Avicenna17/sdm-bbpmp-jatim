# Phase 02B — Rule + Calculator

## 1. Objective

Membangun child rules berversi, permanent immutability setelah penggunaan, explicit rule lookup, pure exact conversion calculator, serta centralized rounding/presentation policy.

Implementasi dibagi menjadi **02B.1–02B.5**. Dokumen ini merupakan final approved implementation plan. Checkpoint penyimpanan ini hanya dokumentasi; implementasi Phase 02B belum dimulai.

## 2. Current repository baseline

- Branch: `feature/pak-phase-02`.
- HEAD dan peeled tag `pak-phase-02a`: `51b5de7`.
- Working tree bersih sebelum checkpoint dokumentasi ini.
- `PakRuleSetStatus` hanya memiliki `DRAFT`.
- `PakCaseService` mengisi `first_used_at` ketika rule set pertama kali digunakan case; preview tidak mengisi marker.
- `PakRuleSet` menolak perubahan setelah digunakan. `PakModel` melarang delete.
- `PakRuleSetService` menggunakan permission `pak.rule-set.manage`.
- Default database collation `utf8mb4_unicode_ci`.
- `brick/math` 0.14.8 tersedia sebagai dependency transitif, belum direct dependency.
- Dua child-rule tables dan calculator belum tersedia.

## 3. Authoritative decisions — RESOLVED

| Topik | Keputusan |
|---|---|
| Factor field | `predicate_factor`; `percentage` pada desain lama obsolete |
| Rule-set status | Tetap DRAFT; tidak menambah ACTIVE/PUBLISHED/USABLE |
| First use | Tetap penggunaan case Phase 01 |
| Child sebelum penggunaan | Seluruh key/value/label boleh dikoreksi; parent ID tetap immutable |
| Child setelah penggunaan | Seluruh field immutable permanen |
| Delete child | Tidak diperbolehkan, termasuk sebelum digunakan |
| Copy version | Version eksplisit, target DRAFT, marker NULL, child IDs baru |
| Predicate lookup | Exact case-sensitive; tanpa alias/fuzzy/case conversion |
| Coefficient key | Rule-set ID + canonical functional-level ID |
| Used rule set | Tetap resolvable untuk historical reproducibility |
| Inactive level | Tetap boleh explicit lookup untuk reproducibility |
| Month count | Native integer positif, tanpa cap bisnis 12/36 |
| Arithmetic | Exact rational; tidak menggunakan PHP float authoritative |
| Rounding | BR-KONV-004 APPROVED — client confirmation 23 Oct 2026; exact calculation, max 3 decimals HALF_UP for output, human display comma + trimmed trailing zeros |
| PREVIEW_ONLY | Batas lifecycle hasil Phase 02, bukan pembatas approval rounding |
| Audit | Event create/update terpisah dan `RULE_SET_COPIED` |

**APPROVED berarti konfirmasi bisnis client sesuai repository, bukan klaim verifikasi regulasi baru.**

Phase 02 tetap hanya memiliki lifecycle hasil PREVIEW, REQUIRES_REVIEW, dan SUPERSEDED; belum melakukan finalization/penetapan PAK.

## 4. Scope dan out-of-scope

**Termasuk:** dua child-rule tables/models, model guards, lifecycle service, explicit copy, explicit resolver, exact calculator, rounding policy, dan automated tests.

**Tidak termasuk:** date/month counting, annual-versus-quarterly selection, position-history segmentation, temporal validity coverage, conversion runs/periods, replacement chain, stale-preview save, UI, akumulasi, penetapan, KP/KJ.

Tidak mengubah source architecture, fingerprint, preparation/commit/correction, atau schema Phase 02A.

## 5. Schema proposal

Tepat dua additive migrations setelah migrations existing.

### `performance_predicate_rules`

| Kolom | Definisi |
|---|---|
| `id` | BIGINT unsigned primary auto-increment |
| `pak_rule_set_id` | BIGINT unsigned NOT NULL; FK `pak_rule_sets.id`, RESTRICT |
| `predicate_code` | VARCHAR(50) NOT NULL, case-sensitive |
| `predicate_name` | VARCHAR(100) NOT NULL |
| `predicate_factor` | DECIMAL(8,4) NOT NULL |
| `created_at`, `updated_at` | Laravel timestamps |

Unique `ppr_rule_code_uq`: `(pak_rule_set_id, predicate_code)`.

Gunakan `utf8mb4_bin` pada kolom code di MariaDB/MySQL; SQLite menggunakan BINARY comparison. Tidak mengubah collation global.

### `ak_coefficient_rules`

| Kolom | Definisi |
|---|---|
| `id` | BIGINT unsigned primary auto-increment |
| `pak_rule_set_id` | BIGINT unsigned NOT NULL; FK `pak_rule_sets.id`, RESTRICT |
| `functional_level_id` | BIGINT unsigned NOT NULL; FK `functional_levels.id`, RESTRICT |
| `annual_coefficient` | DECIMAL(15,6) NOT NULL |
| `created_at`, `updated_at` | Laravel timestamps |

Unique `acr_rule_level_uq`: `(pak_rule_set_id, functional_level_id)`. Index eksplisit pada `functional_level_id`.

Field domain wajib tidak memiliki default numerik. Tidak menambah status, soft delete, employee reference, atau dimensi position/category.

Urutan migration: predicate rules → coefficient rules. Rollback kebalikannya, sebelum parent tables. Tidak ada migration conversion persistence.

## 6. Models dan invariants

Models baru:

- `PerformancePredicateRule`
- `AkCoefficientRule`

Keduanya extends `PakModel`, belongsTo `PakRuleSet`; coefficient belongsTo `FunctionalLevel`. Parent memperoleh dua hasMany relationships.

Casts: `decimal:4` untuk factor dan `decimal:6` untuk coefficient.

### DRAFT dan belum digunakan

Selama persisted parent berstatus DRAFT dan `first_used_at IS NULL`, service boleh membuat atau memperbarui child, termasuk:

- `predicate_code`;
- `predicate_name`;
- `predicate_factor`;
- `functional_level_id`;
- `annual_coefficient`.

`pak_rule_set_id` tetap immutable setelah insert. Child tidak boleh dipindahkan ke parent lain.

Perubahan code/functional-level key harus melewati validasi yang sama dengan create dan tetap memenuhi unique constraint parent+key. Unique collision harus menggagalkan operasi secara atomik, termasuk parent metadata dan audit.

### Setelah digunakan

Setelah parent `first_used_at` non-null:

- tidak boleh membuat child baru;
- seluruh key/value/label dan field child immutable;
- tidak boleh memindahkan child;
- tidak boleh menghapus child;
- koreksi dilakukan melalui copy versi baru.

### Validasi umum

- Delete selalu ditolak, termasuk pada unused DRAFT.
- Code nonblank, outer-trim saat write, case dipertahankan; tidak membuat alias otomatis.
- Factor/coefficient harus positive exact decimal strings.
- Tolak float, scientific notation, separator lokal, overflow, dan precision yang tidak dapat direpresentasikan tepat. Jangan membulatkan input agar muat.
- Tidak menambah numeric business maximum selain kapasitas schema.
- Service memegang parent lock sepanjang pemeriksaan dan mutation.
- Direct SQL/bulk updates bukan domain write path yang sah.

## 7. Rule-set lifecycle

Pertahankan behavior dan tests Phase 01:

- DRAFT belum digunakan: metadata dan seluruh editable child fields boleh dikoreksi.
- Case first use: parent dikunci, marker diisi dalam transaksi case.
- Setelah digunakan: parent/child immutable; marker tidak boleh dikosongkan atau diganti.
- Pergantian rule set pada case tidak membuka versi sebelumnya.
- Preview, resolver, dan calculator tidak menandai penggunaan.
- Used rule set tetap dapat dibaca dan di-resolve.
- Phase 02C menggunakan rule set case secara konsisten, bukan menunda first use hingga conversion run.

Child write dan copy mengunci parent terlebih dahulu. Child write memperbarui parent `updated_by` dalam transaksi yang sama. Tidak membuat event first-use baru hanya untuk Phase 02B.

## 8. Copy-version contract

```php
PakRuleSetService::copyVersion(
    User $actor,
    int $sourceRuleSetId,
    string $newVersion
): PakRuleSet
```

Contract:

1. Authorize actor aktif, `pak.access`, `pak.rule-set.manage`, dan `pak.rule-set.view`.
2. Validasi version eksplisit; tidak auto-increment.
3. Dalam satu transaksi, lock source parent dan baca child secara current read.
4. Copy code, name, valid_from, valid_until, dan reference.
5. Target DRAFT, `first_used_at = NULL`; created/updated actor adalah copier.
6. Copy seluruh child dengan ID baru, tanpa sharing/moving.
7. Audit `RULE_SET_COPIED` pada target.
8. Commit.

Unique `(code, version)` tetap DB guard. Child/audit failure me-rollback seluruh target. Source tidak berubah.

Audit copy menyimpan source/target ID, version/status dan child counts minimum, bukan seluruh child payload. Tidak membuat event CREATED per copied child.

Target unused DRAFT dapat diedit, termasuk child code/functional-level key. Setelah digunakan case, target menjadi immutable sesuai lifecycle yang sama.

## 9. Explicit RuleResolver

```php
resolvePredicate(int $ruleSetId, string $predicateCode): ResolvedPredicateRule
resolveCoefficient(int $ruleSetId, int $functionalLevelId): ResolvedCoefficientRule
```

DTO immutable membawa rule-set ID, child ID, exact key, label bila relevan, serta exact decimal string.

- Query persisted state, bukan stale cached relationship.
- DRAFT diterima baik `first_used_at = NULL` maupun non-null.
- **Used rule set wajib tetap resolvable** untuk historical reproducibility.
- Resolver menerima canonical code; tidak memperbaiki whitespace/case.
- Lookup hanya pada rule set eksplisit.
- Inactive functional level tetap boleh dibaca.
- Tidak ada fallback, date resolution, alias label, JF mapping, atau suitability decision.
- Tidak ada mutation atau first-use marking.
- Authorization berada pada application entrypoint; resolver merupakan internal read component.

Structured errors:

| Issue | Makna |
|---|---|
| `RULE_SET_NOT_FOUND` | Rule set eksplisit tidak ditemukan |
| `RULE_SET_NOT_USABLE` | Unsupported/corrupt lifecycle state; bukan karena sudah digunakan |
| `PREDICATE_RULE_NOT_FOUND` | Exact predicate code tidak ditemukan dalam versi tersebut |
| `COEFFICIENT_RULE_NOT_FOUND` | Exact functional-level key tidak ditemukan dalam versi tersebut |
| `RULE_INTEGRITY_ERROR` | Duplikasi, ownership, atau numeric rule tidak konsisten |
| `INVALID_RULE_INPUT` | Input resolver tidak memenuhi kontrak |

`RULE_SET_NOT_USABLE` tidak boleh dipicu hanya karena `first_used_at` terisi. Tidak menambah status baru sekarang. Status future hanya dapat memperoleh semantics tambahan melalui keputusan eksplisit; resolver tidak menebak lifecycle baru.

Deteksi duplikasi/integrity dengan hasil maksimal dua record; jangan memilih arbitrary record. Validasi ownership dan numeric values.

## 10. Exact math strategy

Pada 02B.4:

- Tambahkan direct requirement `brick/math: ^0.14.8`.
- Pertahankan locked version 0.14.8; tidak melakukan broad dependency update.
- Parsing decimal strings melalui `BigDecimal`.
- Arithmetic bulan/12, faktor, coefficient, line, dan total memakai `BigRational`.
- Canonical exact result menggunakan simplified numerator/denominator, diekspor sebagai integer strings dengan denominator positif.
- Jangan mengubah authoritative values menjadi PHP float.

Tidak ada dependency change pada planning checkpoint.

## 11. ConversionCalculator API

```php
ConversionInput::from(
    mixed $monthCount,
    mixed $predicateFactor,
    mixed $annualCoefficient
): ConversionInput

ConversionCalculator::calculate(ConversionInput $input): BigRational
ConversionCalculator::total(iterable $exactLines): BigRational
```

Factory melakukan runtime validation agar PHP scalar coercion tidak menerima float secara diam-diam.

- Month count wajib native integer >0.
- Factor/coefficient wajib positive decimal strings.
- DTO immutable dengan constructor non-public.
- Formula tepat: `month_count / 12 × predicate_factor × annual_coefficient`.
- `total()` menerima exact nonnegative `BigRational`; empty iterable menghasilkan mathematical identity `0/1`, bukan izin membuat conversion run kosong.
- Tidak ada tanggal, employee, case, database, filesystem, authorization, atau browser request API.

Future browser values tidak authoritative. Phase 02C/02D harus resolve dan menghitung ulang server-side.

## 12. Rounding dan presentation policy

`ConversionRoundingPolicy` pure dan terpusat:

```text
profile: PREVIEW_ONLY
version: 1
scale: 3
rounding: HALF_UP
```

BR-KONV-004 sudah APPROVED. Profile tidak menyatakan rounding pending; PREVIEW_ONLY membatasi lifecycle hasil Phase 02.

### Machine decimal

```php
decimal(BigRational $value): string
```

Menghasilkan canonical fixed three-decimal string bertitik:

```text
18.750
20.833
0.500
```

### Human display

```php
display(BigRational $value): string
```

Menggunakan koma desimal, trim trailing zero, tanpa thousands separator:

```text
100
18,75
12,5
20,83
20,833
0,5
0
18,754
18,755
```

Gunakan `RoundingMode::HalfUp`, bukan float rounding/formatting.

### Exact total tetap authoritative

```text
exact line = 25/12
machine decimal = 2.083
human display = 2,083

3 exact lines:
exact total = 25/4 = 6.25

sum displayed values numerically:
2,083 + 2,083 + 2,083 = 6,249

human display exact total = 6,25
```

Displayed line values tidak pernah menjadi operand total. Presentation tidak membulatkan atau mengganti exact internal values.

## 13. Golden numeric fixtures

| Month count | Factor | Coefficient | Exact | Machine | Human |
|---:|---:|---:|---:|---:|---:|
| 2 | 1 | 25 | 25/6 | 4.167 | 4,167 |
| 12 | 1 | 25 | 25/1 | 25.000 | 25 |
| 12 | 1 | 25 | 25/1 | 25.000 | 25 |
| 10 | 1 | 25 | 125/6 | 20.833 | 20,833 |
| Total | | | **75/1** | **75.000** | **75** |

Nurmaulida tetap numeric fixture, tanpa mapping JF rekaan. Tidak memasukkan AK Dasar 100, AK Integrasi, old conversion, atau cumulative 175.

Tambahan:

- `12/12 × 1.50 × 12.5 = 75/4`.
- `12/12 × 1.00 × 25 = 25`.
- `6/12 × 1.00 × 37.5 = 75/4`.
- `3/12 × 0.25 × 3.75 = 15/64`.
- Semua faktor/koefisien terdokumentasi sebagai fixtures.
- Months >12 valid secara arithmetic, tanpa klaim validitas period bisnis.

## 14. Automated test matrix

### Schema/model

- Fresh/upgrade/rollback; FK RESTRICT; unique keys.
- Case-sensitive lookup/uniqueness dan exact decimal scale.
- Invalid numeric input dan forbidden rounding.
- Typo predicate code dapat diperbaiki sebelum first use.
- Coefficient dapat diarahkan ke functional level yang benar sebelum first use.
- Perubahan key yang bertabrakan dengan unique constraint ditolak atomik.
- Parent ID tidak dapat diubah.
- Seluruh key/value/label mutation ditolak setelah first use.
- Delete ditolak pada unused maupun used parent.
- Stale instance tidak dapat melewati used-parent protection.

### Lifecycle/copy

- Authorized create/update, termasuk editable child keys.
- Case first-use regression.
- Independent copied IDs.
- Audit/child failure rollback.
- Concurrent child mutation versus case first use terserialisasi parent lock.
- Key-collision failure tidak meninggalkan perubahan parent metadata atau audit.

### Resolver

- Exact hit/missing/case mismatch; no alias/fallback.
- Inactive level tetap resolvable.
- DRAFT unused dan used sama-sama diterima.
- `first_used_at` non-null tidak menghasilkan `RULE_SET_NOT_USABLE`.
- Unsupported/corrupt lifecycle ditolak tanpa menambah status baru.
- Corrupt/duplicate rule rejection.
- No write/marker mutation.

### Calculator/presentation

- Exact fractions, determinism/no DB.
- Zero/negative/null/fractional/float month rejection.
- Invalid factor/coefficient dan exact multi-line total.
- Machine dot versus human comma.
- Trailing zeros dan rounding edges `18.7544`, `18.7545`, `18.7546`.
- Perbedaan rounded-line sum versus exact-total display.

SQLite tidak menjadi bukti production DECIMAL storage atau concurrency.

## 15. Implementation slices

### 02B.1 — Rule Schema + Models

Files:

- Dua migrations child-rule tables.
- Dua models baru dan relationships `PakRuleSet`.
- `app/Domain/Pak/Rules/ConversionRuleValues.php` untuk canonical code/exact decimal-string validation, tanpa arithmetic.
- `ConversionRuleSchemaTest.php`, `ConversionRuleModelTest.php`.
- Penyesuaian dependency teardown migration tests existing hanya bila diperlukan.

**Gate:** portable schema/guards, editable unused-child keys, permanent used-child lock, dan fresh/upgrade/rollback lulus. Belum CRUD service, copy, resolver, calculator, atau UI.

### 02B.2 — Rule Lifecycle + Version Copy

Files:

- `Services/ConversionRuleService.php`.
- `PakRuleSetService` untuk copy.
- Restricted child-subject additions pada `PakAuditService`.
- `ConversionRuleLifecycleTest.php`, `PakRuleSetCopyTest.php`.

API write: `savePredicate(actor, ruleSetId, input, ?childId)` dan `saveCoefficient(...)`, dengan explicit field whitelist.

Update child boleh mengubah code atau functional-level key selama parent unused DRAFT. Service memverifikasi child tetap milik parent yang diberikan; tidak memindahkan child.

Events:

- `PREDICATE_RULE_CREATED`
- `PREDICATE_RULE_UPDATED`
- `COEFFICIENT_RULE_CREATED`
- `COEFFICIENT_RULE_UPDATED`
- `RULE_SET_COPIED`

Audit mempertahankan top-level whitelist `before/after/reason/supersedes_id`; nested fields eksplisit, tanpa whole-model serialization.

**Gate:** parent lock, key-collision atomicity, mutation/audit atomicity, copy independence, dan Phase 01 first-use regression lulus.

### 02B.3 — Explicit Rule Resolver

Files:

- `Services/RuleResolver.php`.
- Dua resolved DTO.
- `Exceptions/ConversionRuleException.php`.
- `RuleResolverTest.php`.

**Gate:** exact lookup dan structured errors lulus; used DRAFT tetap resolvable. Tidak ada effective-date/source/position resolver. Authoritative save transaction tetap milik 02C.

### 02B.4 — Pure Exact Conversion Calculator

Files:

- Direct Composer requirement.
- `DTOs/ConversionInput.php`.
- `Calculators/ConversionCalculator.php`.
- `ConversionCalculatorTest.php`.

Pure tests mengikuti existing discovery folder `tests/Feature/Pak`, tanpa boot database.

**Gate:** exact arithmetic, strict input validation, determinism/no-DB tests lulus. Tidak ada persistence atau rounding arithmetic.

### 02B.5 — Presentation Policy + Golden Regression

Files:

- `Services/ConversionRoundingPolicy.php`.
- `ConversionRoundingPolicyTest.php`.
- `ConversionGoldenTest.php`.

**Gate:** **BR-KONV-004 rounding policy dan golden numeric tests lulus, tanpa mengklaim Phase 02 sebagai finalization/penetapan PAK.**

Tidak ada UI, document generation, accumulation, atau conversion persistence.

## 16. Migration/deployment compatibility

- Tidak mengubah existing migrations, timestamp semantics, isolation, atau Phase 01/02A schema.
- Tidak memakai database ENUM/CHECK daftar status.
- MariaDB wajib membuktikan DECIMAL round-trip, binary code semantics, FK, dan parent-lock serialization.
- Production target tetap 10.5.10; known legacy fresh-schema `42S22/1054` dicatat terpisah.
- Gunakan disposable upgrade baseline; bila fresh tests memakai 10.5.11, laporkan versi sebenarnya.
- Jangan menjalankan verification migrations pada shared/development/production database.

## 17. Acceptance criteria

- Tepat dua child-rule tables, tanpa conversion persistence.
- Case-first-use behavior tetap.
- Unused DRAFT child dapat dikoreksi termasuk key, tanpa perpindahan parent.
- Used parent/child tidak dapat dimutasi atau dibuka kembali.
- Copy atomic dan independen.
- Resolver exact dengan explicit version; used rule set tetap reproducible.
- Calculator pure/exact dan golden total 75 lulus.
- Machine/human presentation sesuai kontrak, tanpa mengubah total exact.
- Relevant Phase 01/02A regression, authorization/audit, Pint, PHP lint, dan diff check lulus.
- Tidak ada active-regulation seeder atau fabricated JF mapping.

## 18. Open issues dan planning status

**BR-KONV-004 tidak termasuk pending/open issues.**

Hal yang tetap di luar implementasi 02B:

- Pengesahan dataset master regulasi aktif dan explicit alias data.
- Mapping resmi JF/jenjang Nurmaulida.
- Pending existing BR-SKP-008, BR-PERIOD-003, BR-APPOINT-008, BR-KJ-005, BR-BAL-003; BR-BAL-004 tetap proposed.
- Temporal validity/suitability dan segmentation 02C.
- Known fresh-schema MariaDB 10.5.10 verification limitation.

Tidak ada keputusan inti 02B yang masih memblokir plan setelah klarifikasi.

Dua koreksi terakhir telah diterapkan pada invariant, lifecycle service, resolver semantics, test matrix, dan acceptance gates:

1. Child key/value/label editable selama parent unused DRAFT; parent ID tetap immutable.
2. Used DRAFT tetap resolvable; `RULE_SET_NOT_USABLE` bukan akibat terisinya `first_used_at`.

Final approved plan telah disimpan sebagai dokumentasi repository. Checkpoint ini tidak mengubah source, migration, dependency, atau executable test. Implementasi 02B.1 belum dimulai.
