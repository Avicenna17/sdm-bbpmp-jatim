# Phase 02C Implementation Plan — FINAL APPROVED

## Resolution + Conversion Runs

Approval ini mengunci struktur, keputusan resolved, dan delivery gates berikut. Open decisions yang tercatat tetap wajib diselesaikan sebelum slice terkait; implementer tidak boleh mengisinya dengan asumsi.

## 1. Objective

Membangun resolution dan conversion runs yang:

- memilih sumber SKP authoritative;
- menjelaskan coverage hingga tingkat bulan;
- menentukan histori jabatan/jenjang applicable;
- menggunakan satu rule set eksplisit;
- menghitung melalui exact calculator Phase 02B;
- menyimpan calculation snapshot immutable;
- mendukung stale assessment, explicit review, dan replacement tanpa menimpa histori.

Phase 02C tidak melakukan akumulasi atau penetapan PAK.

## 2. Current accepted baseline

**EXISTING CONTRACT**

- Branch: `feature/pak-phase-02`.
- Accepted HEAD: `fde4a12`.
- Checkpoints: `pak-phase-02a`, `pak-phase-02b`.
- PakCase memiliki person, nullable rule set, periode, snapshot, status, dan optimistic `lock_version`.
- PakCase first-use menetapkan `PakRuleSet.first_used_at` secara permanen.
- PerformanceEvaluation memakai CURRENT/SUPERSEDED dan current-identity projection unik.
- Correction mempertahankan identity dan original predecessor; memakai top-level transaction serta bounded deadlock retry.
- Evaluation–history FK bersifat optional.
- EmployeePositionHistory verified/versioned; write existing mengunci Person.
- RuleResolver memakai explicit rule set dan exact keys; used DRAFT/inactive level tetap resolvable.
- Calculator menggunakan exact BigRational.
- Rounding memakai `PREVIEW_ONLY` v1, scale 3, HALF_UP.
- Audit top-level whitelist: `before`, `after`, `reason`, `supersedes_id`.

SKP commit dan evaluation creation/correction belum seluruhnya mengikuti Person coordination protocol. Penambahannya menjadi prerequisite eksplisit Phase 02C.

## 3. Authoritative decisions dan discrepancies

### RESOLVED / EXISTING CONTRACT

- Annual mengalahkan quarterly, termasuk subset tahun.
- Quarterly relevance mengikuti §6.
- Satu bulan hanya mendapat satu sumber predikat.
- Coverage dokumen berbeda dari applied months.
- Whole-month position menggunakan TMT terbaru yang applicable dalam bulan.
- Single unambiguous annual tidak mensyaratkan history FK.
- Multiple annual dan kebutuhan consistency memakai proof order §6.
- Person row menjadi coordination lock per person.
- Stale assessment read-only; review mutation eksplisit dan authorized.
- Calculation snapshot immutable.
- Annual/correction tidak otomatis mengganti run.
- Satu case/run menggunakan satu rule set eksplisit.
- PREVIEW_ONLY membatasi lifecycle, bukan approval rounding.

### Discrepancies dokumentasi

| Discrepancy | Acuan implementasi |
|---|---|
| Desain lama memakai decimal month count dan `raw_ak`/`rounded_ak` | Final parent plan/02B: integer month count dan exact rational persistence |
| Desain periods lama belum memiliki run revision lengkap | Run menjadi aggregate owner dengan replacement chain |
| Baseline historis plan 02B menyebut komponen belum tersedia | Actual accepted implementation menjadi baseline |
| Parent plan dan accepted 02B.2 berbeda pada dependency view child-write | Tidak mengubah accepted 02B.2; conversion memakai permission contract tersendiri |

Planning tidak mengubah approval status business rules.

## 4. Scope dan boundaries

### Enam slice

1. **02C.1 — SKP Source Selection**
2. **02C.2 — Monthly Coverage Resolution**
3. **02C.3 — Position Segmentation**
4. **02C.4 — Conversion Run Schema + Persistence**
5. **02C.5 — Conversion Run Orchestration**
6. **02C.6 — Replacement + Recalculation + Concurrency**

Perubahan coordination pada accepted 02A diperbolehkan sebagai prerequisite correctness dengan scope dan regression eksplisit.

**DEFERRED TO 02D:** Filament, upload/correction UI, evidence viewer, comparison screens, badges, tables, dashboard.

**DEFERRED TO PHASE 03:** akumulasi, penetapan, finalization, saldo, KP/KJ.

**Phase 02E:** whole-phase regression, UAT, dan acceptance/deployment verification menyeluruh.

## 5. Terminology

- **Requested year partition:** bagian validated requested range dalam satu performance year.
- **Source coverage:** coverage asli dokumen.
- **Applied months:** bulan penerapan predikat menurut aturan bisnis.
- **Position month:** histori terpilih untuk satu bulan.
- **Calculation line:** bulan contiguous dengan inputs dan provenance yang sama.
- **Run:** calculation/diagnostic snapshot pada case.
- **Stale assessment:** perbandingan dependency saat ini dengan snapshot dependency.
- **Replacement:** successor eksplisit terhadap latest non-superseded run.

## 6. 02C.1 — SKP Source Selection

### Components

- `SkpSourceSelectionResolver`.
- Immutable candidate/selection DTOs.
- `SkpSourceSelectionTest`.

### Active source eligibility — RESOLVED

Candidate untuk active Phase 02 conversion wajib memenuhi:

- `status = PerformanceEvaluationStatus::CURRENT`;
- `source_kind = PerformanceSourceKind::OFFICIAL`;
- `evaluation_type` salah satu `ANNUAL`, `Q1`, `Q2`, `Q3`, `Q4`;
- person dan performance year sesuai partition.

`OFFICIAL` adalah backed enum value existing, bukan adjective bebas.

PROVISIONAL dan CUSTOM_PERIOD tetap historical-readable, tetapi tidak masuk active candidate set. Resolver tidak menghapus atau mengubahnya. Aktivasi keduanya di masa depan memerlukan explicit business-rule decision.

### Input

- Person ID.
- Requested year partitions yang dibuat server.
- Persisted CURRENT eligible candidates.
- Position-month context ketika diperlukan untuk membedakan sources.

Pilihan browser tidak boleh mengalahkan prioritas server.

### Source priority — OD-01 RESOLVED

Untuk setiap requested year partition:

1. Jika annual tersedia, gunakan annual priority untuk seluruh requested months tahun tersebut.
2. Tanpa annual, quarterly eligible memenuhi:

   `quarter_start_month <= requested_end_month`

3. Pilih quarterly bernomor tertinggi yang eligible.
4. Jika lebih dari satu candidate pada priority tertinggi tidak dapat dibedakan secara authoritative, hasilnya `AMBIGUOUS_SOURCE_SELECTION`.

| Type | Quarter-start month |
|---|---:|
| Q1 | 1 |
| Q2 | 4 |
| Q3 | 7 |
| Q4 | 10 |

Locked examples:

- Jan–May + Q2 → Q2.
- Jan–May + Q3 → Q3 tidak eligible.
- Jan–Oct tanpa Q4/annual → Q3 jika tersedia.
- Jan–Oct + Q4 → Q4.
- Annual tersedia → annual priority.

`source_date` tidak menjadi relevance filter v1; tetap provenance.

Formal source coverage tidak harus mengandung seluruh applied months. Coverage Q3 tetap Juli–September ketika diterapkan pada Januari–Oktober.

### Evaluation–history proof — OD-02 RESOLVED

Tepat satu CURRENT OFFICIAL ANNUAL dapat dipilih tanpa history FK sebagai prerequisite.

History proof wajib untuk membedakan multiple annual atau ketika source/history consistency perlu dibuktikan.

**A. Explicit `employee_position_history_id`**

- Same person.
- Verified, non-superseded history.
- Provenance consistent.

FK invalid, mismatched, atau menunjuk superseded history menghasilkan review; jangan mencari pengganti otomatis untuk melewati FK bermasalah.

**B. Exact reconstruction jika explicit link tidak tersedia**

- Same person.
- `source_position_tmt == history.tmt_start`.
- Documentary provenance yang tersedia dan sebanding—SK number/date/reference—konsisten.
- Tepat satu verified/current history.

Position name/level label hanya consistency evidence, bukan canonical key. Tidak ada fuzzy matching atau alias otomatis.

Zero matches, multiple matches, atau mismatch:

`SOURCE_POSITION_LINK_REQUIRES_REVIEW`

### Multiple annual

Hubungkan candidate melalui proof tersebut, kemudian gunakan posisi/TMT applicable per bulan. Annual priority tetap berlaku, tetapi evaluation ID dapat berbeda antarbulan.

Jangan memakai DB ID, import order, created_at, atau arrival order sebagai tie-break.

### Output

- Evaluation ID dan fingerprints.
- Performance year/type/predicate.
- Original coverage.
- Applied months.
- Selection reason.
- History proof method/references bila diperlukan.
- Structured issues.

### Gate

Eligibility, priority, proof, ambiguity, source-date independence, dan historical retention tests lulus.

## 7. 02C.2 — Monthly Coverage Resolution

### Components

- `MonthlyCoverageResolver`.
- Immutable `MonthlyCoverage`.
- `MonthlyCoverageResolverTest`.

### Requested-range guard — RESOLVED

- Requested start/end valid dan membentuk rentang non-empty.
- Start tidak boleh setelah end.
- Seluruh requested range berada dalam authoritative PakCase period.
- Caller tidak boleh memperluas case period.
- Year partitions dibuat server-side dari validated range.
- Request di luar case period tidak boleh menghasilkan saved run.
- ConversionService tidak mengubah case period secara implisit.
- Perubahan case period harus melalui PakCase lifecycle terlebih dahulu.

Validasi containment pada preview memakai persisted case. Saat save, validasi diulang terhadap case yang sudah dikunci dan dibaca ulang.

Range satu tanggal tetap tunduk pada keputusan partial-period; validitas tanggal tidak otomatis berarti full-month calculable.

### Coverage output

- Ordered canonical months `YYYY-MM`.
- Year partitions.
- Source assignment per bulan.
- Gaps/ambiguities.
- Integer month count.
- Structured issues.

### EXISTING CONTRACT

- Full-month segment dimulai tanggal 1 dan berakhir tanggal terakhir bulan.
- Month count inklusif dari pasangan tahun/bulan.
- Split lintas tahun.
- Tidak ada cap universal 12/36 bulan.
- Duplicate month assignment menolak save.
- Gap tidak diisi predikat atau nol.
- Requested dates asli dipertahankan.

### OD-03 — RESOLVED: Partial Requested Range

Phase 02 conversion menggunakan **FULL-MONTH calculation semantics**. Requested range directly calculable pada aspek boundary bulan hanya jika tanggal valid, rentang non-empty, requested start <= requested end, seluruh range contained dalam authoritative PakCase period, start adalah tanggal 1 bulan awal, dan end adalah hari terakhir valid bulan akhir, termasuk leap year. Boundary yang valid tidak menggantikan validasi source, history, rule, gap, atau eligibility lainnya.

Jika start atau end partial, pertahankan exact original requested dates dan hasilkan structured issue `PARTIAL_MONTH_REQUIRES_REVIEW`. Jangan melakukan day proration, date rounding, auto-expand, atau silent dropping partial month. Partial month bukan zero. Jangan menghasilkan complete canonical counted-month list/count untuk seluruh request atau menghitung hanya full months di tengah lalu menganggap complete conversion tersedia.

Review issue tidak mengotorisasi perhitungan partial month. Direct calculation memerlukan explicit revised requested range yang full-month dan tetap contained dalam PakCase period, atau future explicit business decision. Resolver tidak mengubah PakCase period atau membuat corrected requested range otomatis. Jika case period perlu berubah, gunakan PakCase lifecycle terlebih dahulu.

Partiality ditentukan dari requested start/end, bukan TMT tengah/akhir bulan atau perbedaan formal SKP coverage dengan applied months. BR-JAB-001 whole-month semantics tetap berlaku. OD-03 tidak menyelesaikan OD-04 history end/gap, BR-PERIOD-003 rolling window, atau keputusan bisnis lain.

**Implementation gate 02C.2:** ikuti contract OD-03 yang telah resolved; validate tanggal, urutan dan containment; preserve original dates; detect partial boundary tanpa normalisasi; generate canonical months, server-side year partitions dan inclusive count hanya untuk full-month-calculable range. Invalid date, reversed range dan out-of-case range tetap validation rejection masing-masing, bukan partial-month review. Planned acceptance cases tercatat pada TC-PAK-039.

## 8. 02C.3 — Position Segmentation

### Components

- `PositionSegmentResolver`.
- Immutable `PositionSegment`.
- `PositionSegmentResolverTest`.

### Resolution

Baca histori canonical `people.id` yang:

- verified;
- tidak superseded;
- applicable;
- memiliki provenance TMT/SK yang dipertahankan.

Setelah continuity dan no-overlap untuk seluruh bulan terbukti sesuai OD-04, pilih TMT terbesar yang applicable pada setiap bulan. Perubahan awal/tengah/akhir bulan memakai posisi terbaru untuk seluruh bulan perubahan; latest TMT tidak menutup documentary gap atau menyelesaikan overlap.

Tidak:

- menerapkan posisi terakhir ke seluruh rentang historis;
- prorata hari;
- mengubah tanggal histori;
- menciptakan overlap histori;
- membuat mapping JF otomatis.

Inactive master tetap historical-resolvable. Suitability dipisahkan dari historical lookup.

### DTO

- Start/end month, canonical months, month count.
- History ID.
- Functional position/level IDs.
- Original TMT interval.
- SK/source/verification references.
- Issues.

### Regime

BR-REGIME-001 tetap berlaku. LEGACY/AK Integrasi tidak dihitung atau ditambahkan oleh conversion engine. Canonical review outcome mengikuti OD-12 dan proof TMT Jabatan SK mengikuti OD-13 pada section 11; bukan generic history.tmt_start atau label-based classification.

### OD-04 — RESOLVED: History end/gap dalam bulan

Authoritative position resolution memakai histories milik canonical `people.id` yang verified dan non-superseded. `tmt_start` adalah inclusive start; `tmt_end` adalah inclusive end; `tmt_end = null` berarti open-ended.

Untuk setiap requested calendar month, coverage authoritative wajib terbukti dari hari pertama sampai hari terakhir, tanpa documentary gap atau overlapping authoritative histories. Transisi contiguous hanya jika successor mulai tepat sehari setelah inclusive end predecessor. Successor yang mulai pada tanggal end predecessor adalah overlap. Open-ended predecessor tidak dipotong implicit hanya karena history lain muncul.

Setelah continuity dan no-overlap terbukti, pilih history dengan `tmt_start` terbesar yang applicable dalam bulan. TMT tanggal 1, tengah, atau akhir bulan berlaku untuk seluruh bulan sesuai BR-JAB-001. Tidak ada day proration atau coefficient split dalam satu bulan.

Gap di awal, tengah, atau akhir bulan menghasilkan review, tanpa authoritative monthly assignment untuk bulan terdampak. Jangan memperpanjang history ended, menerapkan successor retroaktif untuk menutup gap, membuat synthetic history, atau mengisi position/predicate/value zero. Mid-month end tanpa contiguous successor dan history ended sebelum bulan tanpa eligible successor juga menghasilkan review.

Overlap authoritative histories dan equal-TMT conflict menghasilkan integrity/review issue. Jangan menyelesaikannya dengan latest TMT saja, database ID, created_at, import order, atau arrival order. Superseded/unverified histories diabaikan dan tidak dapat digunakan untuk menutup gap.

Structured issues menggunakan kode Phase 02C existing:

- `MISSING_POSITION_HISTORY`: tidak ada eligible history atau terdapat documentary gap; dapat membawa affected month, uncovered date interval, dan safe provenance/reference.
- `AMBIGUOUS_POSITION_HISTORY`: authoritative overlap atau equal-TMT conflict; dapat membawa affected month, conflicting interval/history IDs, dan safe provenance/reference.

Jangan memakai `PARTIAL_MONTH_REQUIRES_REVIEW` (requested-range boundary OD-03) atau `SOURCE_POSITION_LINK_REQUIRES_REVIEW` (evaluation-history proof) untuk masalah continuity ini.

| Histories untuk Oktober 2026 | Outcome |
|---|---|
| A end `2026-10-10`; B start `2026-10-20` | REVIEW: gap 11–19 Oktober |
| A end `2026-10-10`; tanpa successor | REVIEW: gap 11–31 Oktober |
| A end `2026-09-30`; B start `2026-10-15` | REVIEW: gap 1–14 Oktober |
| A end `2026-10-20`; B start `2026-10-15` | REVIEW: overlap 15–20 Oktober |
| A end `2026-10-14`; B start `2026-10-15` | Contiguous; B menang untuk seluruh Oktober jika sisa coverage bulan authoritative |

**Implementation gate 02C.3:** OD-04 RESOLVED; terapkan continuity-first dan hasilkan position assignment/provenance atau structured issues. Resolution tetap read-only/deterministic; keputusan ini tidak mengimplikasikan perubahan source/schema. Planned acceptance mengacu TC-PAK-040, tanpa mengubah OD-03 atau keputusan lain.

## 9. 02C.4 — Conversion Run Schema + Persistence

### OD-09 — RESOLVED

Contract schema berikut disetujui setelah DTO 02C.1–02C.3 final (`960532c`). Persistence memakai tepat dua tabel: `pak_conversion_runs` → `pak_conversion_periods`. Tidak ada tabel monthly-assignment atau segment ketiga. Monthly position assignments/proof tetap canonical immutable data dalam versioned dependency manifest; position segments hanya derived grouping.

Period adalah contiguous calculation/diagnostic line dengan source/position/rule/result dependencies yang compatible, bukan salinan langsung `PositionSegment`. Desain lama `database-pak.md` tetap referensi historis; decimal month count, `raw_ak`/`rounded_ak`, dan periods langsung dimiliki case tidak menggantikan contract final ini.

Pada tabel berikut, **I** = immutable setelah authoritative save; **L** = mutable hanya melalui authorized lifecycle service. Semua FK memakai RESTRICT. Ini schema contract untuk implementasi slice berikutnya, bukan migration atau perubahan database pada approval ini.

### `pak_conversion_runs`

| Column | Type / nullable | FK / purpose | Boundary |
|---|---|---|---|
| `id` | unsigned BIGINT, PK | Run identity | I |
| `pak_case_id` | unsigned BIGINT, required | `pak_cases.id` | I |
| `person_id` | unsigned BIGINT, required | `people.id`, canonical identity | I |
| `pak_rule_set_id` | unsigned BIGINT, required | `pak_rule_sets.id`, explicit case rule set | I |
| `revision_number` | unsigned INTEGER, required | Server-assigned revision | I |
| `predecessor_run_id` | unsigned BIGINT, nullable | Self-FK, original predecessor | I |
| `revision_reason` | TEXT, nullable | Required for replacement through service guard | I |
| `status` | VARCHAR(30), required | PREVIEW / REQUIRES_REVIEW / SUPERSEDED | L |
| `active_case_id` | unsigned BIGINT, nullable | `pak_cases.id`, unique current-run projection | L |
| `requested_start`, `requested_end` | DATE, required | Exact original requested range | I |
| `case_lock_version` | unsigned INTEGER, required | Authoritative case version AFTER successful save increment | I |
| `dependency_fingerprint` | CHAR(64), required | Semantic input/dependency fingerprint; not unique | I |
| `snapshot_fingerprint` | CHAR(64), required | Immutable snapshot/result integrity; not unique | I |
| `snapshot_schema_version` | unsigned SMALLINT, required | Manifest/snapshot serialization version | I |
| `calculation_contract_version` | unsigned SMALLINT, required | One pipeline contract; initial canonical application value `1` | I |
| `rounding_profile` | VARCHAR(50), required | Existing PREVIEW_ONLY profile | I |
| `rounding_version` | unsigned SMALLINT, required | Existing policy version, currently `1` | I |
| `dependency_manifest` | JSON, required | Versioned immutable context/dependency/proof data | I |
| `original_issues` | JSON, required | Original issues; empty list if none | I |
| `calculation_trace` | JSON, required | Explicit bounded-layout calculation/proof trace | I |
| `total_numerator`, `total_denominator` | TEXT, nullable pair | Exact total; both NULL if any requested month uncalculated | I |
| `review_assessment` | JSON, nullable | Explicit assessment; not approval | L |
| `review_assessed_by` | unsigned BIGINT, nullable | `users.id`, assessment actor | L |
| `review_assessed_at` | TIMESTAMP, nullable | Assessment time | L |
| `superseded_by_user_id` | unsigned BIGINT, nullable | `users.id`, replacement actor | L |
| `superseded_at` | TIMESTAMP, nullable | Explicit supersession time | L |
| `created_by` | unsigned BIGINT, required | `users.id` | I |
| `created_at`, `updated_at` | Repository timestamps | Creation immutable; updated_at reflects authorized lifecycle mutation | I / L |

`case_lock_version` is the post-save authoritative version: locked case N → validate/recalculate → persist run/periods/audit and increment case atomically → case N+1 and run.case_lock_version N+1. Pre-save expected version is transaction/concurrency input, not the persisted run version. Failure rolls back all changes. Semantic dependency fingerprint excludes optimistic-version increments, so save itself does not make the run stale.

Projection: PREVIEW/REQUIRES_REVIEW → `active_case_id = pak_case_id`; SUPERSEDED → `active_case_id = NULL`. Nullable unique projection enforces at most one current/non-superseded run per case together with mandatory service projection/status/case consistency guards. No partial unique index. Successor is derived through unique predecessor relation; no redundant successor column is required.

### `pak_conversion_periods`

All period fields are immutable after authoritative save.

| Column | Type / nullable | FK / purpose |
|---|---|---|
| `id` | unsigned BIGINT, PK | Line identity |
| `pak_conversion_run_id` | unsigned BIGINT, required | `pak_conversion_runs.id` |
| `line_number` | unsigned INTEGER, required | Deterministic order within run |
| `period_start`, `period_end` | DATE, required | Contiguous full-month applied range |
| `month_count` | unsigned INTEGER, required | Positive inclusive coverage-month count matching boundaries |
| `performance_evaluation_id` | unsigned BIGINT, nullable | `performance_evaluations.id`, selected source |
| `source_position_history_id` | unsigned BIGINT, nullable | `employee_position_histories.id`, source-history proof if used |
| `employee_position_history_id` | unsigned BIGINT, nullable | `employee_position_histories.id`, selected monthly position |
| `functional_level_id` | unsigned BIGINT, nullable | `functional_levels.id` |
| `performance_predicate_rule_id` | unsigned BIGINT, nullable | `performance_predicate_rules.id` |
| `ak_coefficient_rule_id` | unsigned BIGINT, nullable | `ak_coefficient_rules.id` |
| `predicate_factor` | DECIMAL(8,4), nullable, no numeric default | Exact operand snapshot under 02B contract |
| `annual_coefficient` | DECIMAL(15,6), nullable, no numeric default | Exact operand snapshot under 02B contract |
| `result_numerator`, `result_denominator` | TEXT, nullable pair | Exact calculated result |
| `source_snapshot` | JSON, nullable | Source identity/content fingerprints, formal coverage and provenance |
| `position_snapshot` | JSON, nullable | Selected position/history/level/TMT/SK/verification provenance |
| `rule_snapshot` | JSON, nullable | Resolved rule metadata/values when available |
| `original_issues` | JSON, required | Original diagnostics; empty list if none |
| `calculation_trace` | JSON, required | Selection/proof/calculation trace |
| `created_at`, `updated_at` | Repository timestamps | No period update after save |

Diagnostic lines may fail before source/history/rule resolution: unresolved references/snapshots stay NULL, including `rule_snapshot`; do not fabricate `{}` or synthetic rule data. Calculated lines require applicable source/position/rule snapshots, exact operands and results through service invariants. Source-history proof and selected monthly position remain distinct references, without forced equality.

Diagnostic-line month_count describes known full-month coverage, not successful numeric calculation. For OD-03 partial requests preserve exact dates/issues on the run, keep canonical coverage/count unavailable, and do not fabricate periods or count only interior full months. Overall exact total remains NULL. Full-month diagnostics retain affected-month provenance in original issues/trace.

### Canonical month list

**RESOLVED**

Month list berada pada DTO/dependency manifest.

Tidak wajib menyimpan month-list tambahan per line jika contiguous start/end/count cukup. Redundansi memerlukan kebutuhan audit/schema yang jelas.

Noncontiguous months menjadi line berbeda. Guard memastikan boundaries/count konsisten.

### Exact persistence

- Numerator berupa canonical nonnegative integer string, denominator canonical positive integer string; simplified rational form.
- Storage TEXT; no scientific notation, no float, and no requirement to convert to PHP native integer.
- Pasangan sama-sama present atau sama-sama NULL; no zero denominator.
- Bulan tak terhitung menyebabkan total keseluruhan NULL.
- Human/machine presentation direcompute dari exact values dan policy version.
- Tidak menjadikan rounded values authoritative.

DB enforces only safe, simple compatible structural constraints. Canonical integer-string validation, positivity and simplification remain service/value-object invariants where complex MariaDB CHECK logic would reduce portability. Exact operand DECIMAL definitions retain 02B precision/scale; they do not replace rational result storage.

### Dependency manifest v1 dan versions

| Group | Immutable content |
|---|---|
| `case_context` | Case/person/rule identity, relevant authoritative case context and period used |
| `coverage` | Original requested dates, completeness, canonical months/year partitions or unavailable partial state |
| `source_candidates` | Relevant candidate-set evidence, including Annual absence/presence, IDs and fingerprints |
| `selected_sources` | Selected evaluation snapshots, formal coverage versus applied months, selection reason and history proof |
| `position_assignments` | Canonical monthly assignments and supporting continuity references; segments remain derived |
| `history_dependencies` | Original intervals, verification/supersession state and safe documentary provenance needed for replay/stale assessment |
| `rule_set` | Explicit rule-set reference and immutable code/version/validity/reference snapshot |
| `versions` | Snapshot schema, one calculation pipeline contract and existing rounding profile/version |

No whole Eloquent serialization. Live FK references alone do not replace immutable scalar snapshots. Selected source/history/rules retain both; supporting candidate/history references and snapshots live in the manifest without a third table. Future source/history supersession must not rewrite run meaning.

One canonical application constant/value represents initial calculation contract version `1`. Do not create separate algorithm columns for five components. Existing rounding constants remain authoritative; no rounded display columns. Fingerprints are not idempotency keys; OD-08 remains deferred.

### Constraints

- FK RESTRICT.
- Unique `(pak_case_id, revision_number)` — `pcr_case_revision_uq`.
- Unique nullable `predecessor_run_id` — `pcr_predecessor_uq`.
- Unique nullable `active_case_id` — `pcr_active_case_uq`.
- Unique `(pak_conversion_run_id, line_number)` — `pcp_run_line_uq`.
- No overlapping counted months.
- Person/source/history/rule-set consistency.
- No delete atau calculation-snapshot rewrite.

Service guards additionally enforce same-case predecessor, revision sequence, no self-reference/branching, projection/status consistency and cross-person/source/rule ownership. FK/unique constraints are database defenses, not replacements for application locking/OD-05. Add parent `(person_id, created_at)` lookup index and necessary FK indexes without redundant indexes already covered by a leftmost prefix. Do not index exact TEXT/JSON payloads for this slice.

PakRuleSet first-use remains owned by PakCase lifecycle. 02C.4 adds no marker; preview never marks first use. PakCase lifecycle is the sole writer of first_used_at. 02C.5.2 preview and authoritative conversion save never set, repair, or backfill it. Save only verifies the case's already-used rule set; a missing/inconsistent required marker is rejected through RULE_INTEGRITY_ERROR, not repaired or handled by a second lifecycle writer. OD-07 remains BUSINESS PENDING, OD-08 deferred, and OD-11 is RESOLVED for numeric orchestration as specified in section 11.

## 10. Run lifecycle dan immutability

### Status — EXISTING CONTRACT

| Status | Makna |
|---|---|
| PREVIEW | Saved preview tanpa unresolved run issues |
| REQUIRES_REVIEW | Dependency/source/history/rule/coverage membutuhkan pemeriksaan |
| SUPERSEDED | Digantikan secara eksplisit |

Tidak menambah FINAL, APPROVED, atau PENETAPAN.

### Calculation snapshot immutable — RESOLVED

Setelah save, tidak boleh diubah:

- requested calculation context;
- source/evaluation snapshot dan provenance;
- position/history snapshot;
- rule references/snapshots;
- exact operands;
- exact line results;
- exact total;
- original calculation issues/trace;
- predecessor/revision identity.

### Mutable hanya melalui authorized lifecycle service

- Lifecycle status.
- Review-assessment metadata.
- `review_assessed_by` / `review_assessed_at` (assessment actor/time, not approval).
- Supersession metadata.

Lifecycle mutation tidak pernah mengubah calculation snapshot.

Original issue tidak dihapus atau ditulis ulang ketika assessment baru dibuat. Run dengan sebagian line terhitung dapat menyimpan diagnostics, tetapi total lengkap tetap NULL.

### Explicit state machine — OD-10 resolved boundary

| Operation | Persisted outcome |
|---|---|
| Ordinary read-only preview | No row, no audit |
| Authoritative initial save without unresolved run issues | PREVIEW |
| Authoritative initial save with unresolved issues | REQUIRES_REVIEW |
| Explicit assessment requiring review | PREVIEW → REQUIRES_REVIEW |
| Subsequent explicit review assessment update | REQUIRES_REVIEW → REQUIRES_REVIEW |
| Explicit replacement | Predecessor PREVIEW/REQUIRES_REVIEW → SUPERSEDED; successor → PREVIEW or REQUIRES_REVIEW |
| SUPERSEDED | Terminal |

No generic status setter, free-form approval workflow, or in-place REQUIRES_REVIEW → PREVIEW. Assessment explains why a run requires examination; it does not approve AK, finalize PAK, resolve original issues in place, or change the immutable calculation snapshot. Resolved/recalculated dependencies produce an explicit successor subject to replacement business gates. Annual/correction arrival causes no automatic transition or successor.

## 11. 02C.5 — Conversion Run Orchestration

### Components

- `ConversionService`.
- Preview/input/result DTOs.
- Versioned preview fingerprint builder.
- Integration tests.

### Pipeline

1. Authorize.
2. Load authoritative PakCase dan explicit PakRuleSet identity.
3. Periksa rule-set existence, status, dan integrity.
4. Validate requested range/containment dan OD-03 coverage; enumerate months/year partitions server-side hanya jika coverage complete.
5. Terapkan OD-11 validity gate sebelum child numeric resolution; failure short-circuits numeric path seluruh request.
6. Collect eligible CURRENT sources dan resolve position per bulan.
7. Finalize source selection/proof.
8. Bentuk contiguous calculation/diagnostic lines menurut approved period-building contract.
9. Buktikan TMT Jabatan SK menurut OD-13, lalu periksa regime dan suitability.
10. RuleResolver.
11. ConversionInput.
12. ConversionCalculator dan exact total.
13. ConversionRoundingPolicy.
14. Preview DTO/dependency fingerprint.

Orchestration menyatukan source dan position results; kedua resolver tidak saling memanggil secara circular.

### OD-11 — RESOLVED: rule validity dan child resolvability

**Sumber:** keputusan client setelah decision audit pada baseline `7c5b6f8`; bukan klaim verifikasi regulasi baru. Bedakan explicit rule-set selection, temporal validity, dan child-rule resolvability.

Numeric orchestration hanya memakai `PakCase.pak_rule_set_id`. Tidak memilih latest, memilih versi berdasarkan tanggal, fallback, atau split request antarversi. Validity inklusif dan kedua boundary harus diketahui:

```text
valid_from <= requested_start AND requested_end <= valid_until
```

Salah satu atau kedua tanggal NULL berarti UNKNOWN, bukan open-ended. Unknown boundary atau requested range partly/wholly outside validity menghasilkan `RULE_VALIDITY_REQUIRES_REVIEW` untuk seluruh requested calculation: tanpa numeric calculation, tanpa menghitung valid subset/interior months, tanpa silent month drop atau fabricated zero, dan exact total NULL. Tanggal request tetap asli. Authoritative diagnostic save sebagai REQUIRES_REVIEW diperbolehkan hanya jika seluruh authoritative-save gates lain sukses.

Precedence wajib: (1) authoritative case/explicit rule identity → (2) rule existence/status/integrity → (3) requested-range validation/containment dan OD-03 coverage → (4) OD-11 validity → (5) child predicate/coefficient resolution → (6) numeric calculation. Validity failure short-circuits child numeric resolution; jangan menambah unrelated child-resolution issues akibat melanjutkan numeric path setelah global failure.

Full-month request dengan validity failure tetap mempunyai canonical coverage; numeric results dan total NULL. Persisted diagnostic periods mengikuti contract 02C.4 yang sudah approved; OD-11 tidak menetapkan cardinality baru per-month maupun grouped periods. Partial OD-03 tetap tanpa fabricated canonical month list/count/periods; OD-11 tidak mengesampingkannya.

Setelah validity lolos dan applicable regime mengizinkan current-rule lookup, predicate lookup memakai exact code dan coefficient lookup memakai exact canonical `functional_level_id`, tanpa alias, label matching, level substitution, arbitrary tie-break, atau fallback. Missing child membuat affected line diagnostic dan complete total NULL; valid lines lain dapat dihitung menurut existing incomplete-run contract, bukan sebagai subtotal total authoritative.

| Outcome | Contract orchestration |
|---|---|
| `RULE_VALIDITY_REQUIRES_REVIEW` | Global diagnostics-only; bounded reason `UNKNOWN_BOUNDARY` atau `OUTSIDE_VALIDITY` |
| `LEGACY_REGIME_REQUIRES_REVIEW` | OD-12: authoritative TMT Jabatan SK evidence places affected line in LEGACY; non-numeric diagnostic, complete total NULL |
| `REGIME_EVIDENCE_REQUIRES_REVIEW` | OD-13: resolved position/history tanpa cukup documentary TMT Jabatan SK proof; tidak diklasifikasikan LEGACY/CURRENT, affected line non-numeric dan complete total NULL |
| `PREDICATE_RULE_NOT_FOUND` | Missing exact predicate after applicable regime gate; affected line diagnostic |
| `COEFFICIENT_RULE_NOT_FOUND` | Missing exact coefficient untuk canonical level; affected line diagnostic |
| `MISSING_FUNCTIONAL_LEVEL` | Position resolution sukses, tetapi selected authoritative position tidak menyediakan canonical level yang diperlukan untuk coefficient lookup |
| `RULE_INTEGRITY_ERROR` | Duplicate/conflicting child, corrupt validity, atau ownership inconsistency; authoritative save ditolak |
| `RULE_SET_NOT_USABLE` | Unsupported/corrupt rule-set state; authoritative save ditolak |
| `RULE_SET_NOT_FOUND` | Explicit rule set tidak tersedia; authoritative save ditolak |
| `INVALID_RULE_INPUT` | Input resolver tidak memenuhi contract; rejection, bukan review-saveable condition |

Enam review outcomes pertama boleh disimpan sebagai diagnostics hanya jika semua save gates lain sukses. Empat integrity/rejection outcomes terakhir boleh tampil sebagai safe preview diagnostics bila sesuai, tetapi tidak boleh menghasilkan authoritative contradictory run. Position gap/overlap tetap `MISSING_POSITION_HISTORY`/`AMBIGUOUS_POSITION_HISTORY`, bukan `MISSING_FUNCTIONAL_LEVEL`. Dua code yang diperkenalkan OD-11 membedakan temporal validity dari lifecycle/child absence dan missing canonical lookup key dari missing coefficient untuk key yang valid.

`RULE_VALIDITY_REQUIRES_REVIEW` dapat membawa requested_start/end, validity dates, dan rule-set ID/version. Issues/audit hanya safe bounded context; jangan memuat document bodies, NIP, private paths, raw SQL, exception traces, atau whole-model serialization.

DRAFT unused maupun used tetap resolvable oleh RuleResolver. First use membekukan editing, bukan membuat ACTIVE/PUBLISHED. Child predicate/coefficient tidak memiliki inactive lifecycle state; inactive FunctionalLevel tetap historically readable/resolvable. RuleResolver/calculator tetap read-only; temporal gate dimiliki orchestration, bukan automatic rule-set selection.

Used version dengan NULL validity tetap readable tetapi tidak numerically usable menurut OD-11. Jangan unlock/mutate versi historis. Remediasi: create/copy explicit new version, lengkapi validity sebelum first use, lalu associate melalui authorized PakCase lifecycle bila operasi tersebut tersedia/approved; bukan klaim automatic rule-set replacement API.

Preview tetap read-only. Save/recalculation re-resolve dan revalidate gate yang sama; fingerprint/dependency mismatch tetap reject dan re-preview, termasuk diagnostic save. Snapshot calculation lama tidak diubah; validity metadata masuk dependency evidence untuk stale/review assessment. Tidak ada automatic successor. OD-07 BUSINESS PENDING dan OD-08 DEFERRED tidak berubah.

### OD-12 — RESOLVED: canonical LEGACY review outcome

Keputusan client untuk 02C.5.2: `LEGACY_REGIME_REQUIRES_REVIEW` berlaku ketika authoritative evidence menunjukkan **TMT Jabatan SK < 2023-01-01** menurut BR-REGIME-001 pada affected month/line. Authoritative resolved history/provenance dapat menyediakan proof; labels bukan identity keys. Jangan menyamakan boundary ini otomatis dengan `history.tmt_start` tanpa accepted documentary equivalence.

Affected LEGACY line adalah review-saveable diagnostic: run menjadi REQUIRES_REVIEW hanya jika seluruh save gates lain lolos; exact line result dan complete parent exact total NULL. Hentikan current-regime child-rule resolution untuk line tersebut; jangan mengeluarkan `PREDICATE_RULE_NOT_FOUND` atau `COEFFICIENT_RULE_NOT_FOUND` hanya karena current rules sengaja tidak dievaluasi. Jangan fabricate zero atau silently drop line/month. Non-LEGACY lines boleh dihitung diagnostically jika dependencies valid, tetapi nilainya bukan complete authoritative subtotal dan parent total tetap NULL.

Canonical full-month coverage dipertahankan. Diagnostic periods memakai contract 02C.4 dengan issue/trace dan nullable numeric fields yang tersedia; tidak ada bentuk persistence, tabel, atau cardinality baru. Jangan otomatis consume, transfer, backfill, atau menambahkan stored AK Integrasi; jangan reinterpret old-regime AK Integrasi sebagai new conversion output. Penggunaan/transfer AK Integrasi kelak adalah business/lifecycle handling terpisah dari 02C.5.2.

Precedence orchestration: case/rule identity/integrity → requested range/containment → OD-03 → OD-11 → source/position resolution → TMT Jabatan SK evidence (OD-13) → regime/suitability → child-rule resolution → numeric calculation. OD-03/OD-11 short-circuit tetap berlaku; OD-12 tidak mengubah source selection, continuity, exact arithmetic, rounding, atau Golden total 75/1.

### OD-13 — RESOLVED: TMT Jabatan SK Evidence Contract

Selected authoritative EmployeePositionHistory membuktikan documentary TMT Jabatan SK hanya jika seluruh syarat terpenuhi:

- Milik authoritative person.
- Verified menurut existing contract (`verified_by` dan `verified_at` tersedia), non-superseded, dan dipilih melalui OD-04.
- `tmt_start` tersedia.
- `sk_number` tersedia dan nonblank.
- `source_ref` sebagai documentary reference tersedia dan nonblank.

Untuk row documentary-complete tersebut saja, business contract menerima `employee_position_histories.tmt_start` sebagai **effective TMT Jabatan SK untuk regime classification only**. Ini bukan equivalence untuk setiap history dalam database. `sk_date` tetap tanggal dokumen/SK, bukan effective TMT Jabatan dan tidak pernah dipakai untuk boundary 2023. `tmt_start` tanpa syarat di atas, `sk_date`, `source_position_tmt`, free-text `source_ref` sendiri, position names/labels, dan functional-level labels tidak cukup sebagai proof.

Jika proof terpenuhi: TMT Jabatan SK < 2023-01-01 menghasilkan `LEGACY_REGIME_REQUIRES_REVIEW` sesuai OD-12; TMT Jabatan SK >= 2023-01-01 mengizinkan CURRENT regime melanjutkan current child-rule resolution, subject to other gates. Jika position/history berhasil resolved tetapi proof tidak cukup, hasilnya **`REGIME_EVIDENCE_REQUIRES_REVIEW`**, bukan LEGACY atau CURRENT. Position gap/overlap tetap menggunakan issue OD-04, bukan diganti dengan issue evidence.

`REGIME_EVIDENCE_REQUIRES_REVIEW` adalah review-saveable hanya jika seluruh authoritative save gates lolos. Affected line non-numeric, exact result NULL, complete parent exact total NULL. Jangan menjalankan current-regime predicate/coefficient lookup untuk line tersebut atau emit missing-child issues hanya karena lookup sengaja dilewati. Jangan fabricate zero, silently drop month/line, atau consume/transfer/backfill/add stored AK Integrasi. Pertahankan canonical coverage dan bounded provenance melalui existing diagnostic-period contract; tidak ada schema/persistence shape baru.

Precedence: case/rule identity/integrity → requested range/containment → OD-03 → OD-11 → source/position resolution → TMT Jabatan SK evidence → regime/suitability → child-rule resolution → numeric calculation. OD-13 hanya menetapkan proof dan insufficient-evidence outcome; OD-12 tetap mensyaratkan proven TMT Jabatan SK < 2023-01-01 untuk LEGACY. Tidak mengubah OD-03/04/07/08/09/10/11, source selection, exact arithmetic, Golden 75/1, atau coordination order.

### Preview

- Read-only dengan coherent read snapshot; tidak memerlukan coordination locks semata-mata untuk preview.
- Tidak membuat run/period/audit atau memutasi case.
- Tidak set/repair/backfill first_used_at.
- Case tanpa rule set boleh menghasilkan diagnostics, tetapi tidak dapat save run.

### Authoritative save

02C.5.2 hanya initial save untuk case yang belum pernah mempunyai PakConversionRun. Jika ANY run sudah ada, termasuk hanya historical/SUPERSEDED rows, reject: tidak ada idempotent success, replay, replacement, revision allocation, atau automatic return-existing-run. Double initial save diserialisasi oleh Case coordination dengan active-case uniqueness sebagai backstop.

- Authorize ulang.
- Lock/reload case.
- Recheck DRAFT, not finalized, expected version.
- Validate requested range terhadap locked case.
- Ikuti full coordination protocol.
- Reconstruct authoritative dependency graph server-side menggunakan current/locking reads; client calculations, selected dependency IDs, issues, dan manifest bukan authority. Expected case version dan preview fingerprint hanya preconditions.
- Run rule set sama dengan locked case rule set.
- Preview fingerprint/dependency berubah → reject dan preview ulang.
- Persist run/lines/audit dan increment case version atomik; run.case_lock_version menyimpan resulting N+1, bukan pre-save expected N.
- Tidak mengubah case period secara implisit.

Pisahkan semantic fingerprint dari optimistic version agar save sendiri tidak membuat run langsung stale.

Initial-save resource order: Case → Person → RuleSet → relevant shared-master rows → applicable conversion-run check → PerformanceEvaluation rows → EmployeePositionHistory rows. Tidak ada predecessor/replacement run pada initial save. Discovery reads boleh mengidentifikasi lock set, tetapi authoritative graph harus divalidasi ulang setelah relevant locks. Stale graph/fingerprint memerlukan reject/re-preview; jangan memperoleh earlier resource class setelah later class sudah terkunci. Shared-master ordering dan seluruh protocol 02C.5.1 tetap berlaku.

### Delivery decomposition — RESOLVED

02C.5 dapat diserahkan secara internal sebagai:

- **02C.5.1 — Cross-writer Person Coordination Hardening**
- **02C.5.2 — Conversion Preview + Authoritative Save**

Ini hanya decomposition commit/review, bukan slice ketujuh. 02C.5.2 hanya read-only preview dan initial authoritative save. Review-assessment mutation tetap accepted lifecycle contract untuk delivery berikutnya, bukan implementasi atau executable acceptance gate 02C.5.2 meskipun umbrella acceptance menyebutnya; tidak ada pengurangan authorization/audit/state-machine requirements.

Coordination protocol lengkap wajib tersedia sebelum authoritative conversion save dianggap accepted.

## 12. Stale detection dan explicit review

### OD-06 — RESOLVED direction

Gunakan:

1. Read-only domain stale assessment.
2. Explicit authorized review mutation.

Tidak menambahkan scheduler, outbox, atau automatic after-commit invalidation.

### Dependency assessment

Bandingkan:

- semantic case context dan rule set;
- selected evaluation IDs/fingerprints;
- relevant candidate-source set, termasuk annual yang sebelumnya tidak tersedia;
- history interval/verification/supersession/provenance;
- rule references/exact operands;
- relevant evidence metadata;
- algorithm/presentation versions.

Selected quarterly yang tidak berubah tidak membuktikan selection masih valid setelah annual baru muncul.

### Review persistence

- Read-only assessment tidak mengubah persisted state.
- Explicit service mengunci case/run, re-assess, lalu menyimpan review metadata/status dan audit atomik.
- Field metadata adalah `review_assessment`, `review_assessed_by`, `review_assessed_at`; bukan `reviewed_by/reviewed_at`. Event mutation adalah `CONVERSION_RUN_REVIEW_ASSESSED`.
- Annual/correction tidak mengubah angka lama atau otomatis membuat successor.
- Original calculation snapshot tetap immutable.
- Persisted status tidak diklaim otomatis selalu up-to-date; domain assessment disajikan terpisah.

## 13. 02C.6 — Replacement/Recalculation

### EXISTING CONTRACT

- Lock case sebelum menentukan latest/revision.
- First run tanpa predecessor.
- Replacement hanya terhadap latest non-superseded run pada case yang sama.
- Original predecessor tidak dialihkan otomatis.
- Revision number dibuat server.
- Reason wajib.
- Successor/lines, supersession, audit, dan case version atomik.
- Unique predecessor mencegah branching.
- Failed attempt tidak mengubah predecessor snapshot/lifecycle.

### OD-07 — BUSINESS PENDING

BR-SKP-008 tetap pending untuk kebijakan penggantian calculation quarterly setelah annual tersedia.

Technical replacement chain tidak mengesahkan business replacement tersebut. Tidak ada auto replacement.

### OD-08 — Deferred decision

OD-08 tetap DEFERRED untuk replay/idempotency dan later lifecycle API, termasuk 02C.6 bila applicable. Penyelesaiannya bukan prerequisite API initial-only 02C.5.2: case dengan run apa pun wajib ditolak sebagaimana section 11. Jangan menjanjikan idempotent success, replay, replacement/recalculation, revision allocation, return-existing-run, atau menyalin SKP COMMITTED fast path.

## 14. Concurrency dan lock ordering

### OD-05 — RESOLVED technical contract

Person row adalah coordination lock per person, bukan global/table/advisory lock.

Canonical global resource order untuk 02C.5.1 dan future conversion integration:

**Case → Person → RuleSet → relevant shared-master rows → predecessor conversion run → PerformanceEvaluation rows → EmployeePositionHistory rows.**

Setiap service hanya memakai applicable subsequence; jangan memperoleh resource class yang lebih awal setelah class yang lebih akhir sudah dikunci. Shared-master order adalah **mapping → functional position → functional level → category**, dengan IDs ascending dalam setiap table. Lock hanya relevant rows; Person selalu mendahului shared master bila keduanya diperlukan. Tidak ada Person fan-out, table/global/advisory lock. Discovery reads hanya menentukan candidate lock set; authoritative validation memakai current reread setelah relevant locks. Jangan memperoleh master tambahan setelah history locking dimulai; dependency-set mismatch harus gagal aman, bukan membalik lock order.

| Operation | Lock order |
|---|---|
| Conversion initial save (02C.5.2 integration contract) | Case → Person → RuleSet → relevant shared masters → applicable conversion-run check → evaluations deterministic → histories deterministic; no predecessor/replacement |
| SKP batch commit | Batch → Person IDs ascending → evaluation identities deterministic |
| Evaluation correction | Person → original predecessor evaluation |
| Initial/current creation | Person → evaluation/dependency validation/insertion; no new earlier-class locks after later-class locks |
| Position history mutation | Person → relevant shared masters → deterministic current/locking history reads → mutation/audit |
| Shared-master mutation | Relevant shared masters in fixed table/ID order; no Person fan-out |
| DUK commit | ReportingPeriod → ImportBatch → affected existing Person IDs ascending → authoritative reread → mutation |
| Existing PakCase capture/refresh | Case when refreshing → ReportingPeriod when DUK-backed → Person → RuleSet → relevant shared masters → history dependencies |

Batch/ReportingPeriod are existing writer-specific prefixes, not new global coordination locks. Rule-set writers retain their applicable RuleSet/child locking; profile writers retain Person before authoritative profile reads. The global order does not add conversion operations to these writers.

### Implementation requirements

- Semua authoritative dependency writers wajib mengikuti protocol.
- SKP commit mengunci seluruh relevant Person IDs ascending sebelum evaluation mutation.
- Nested `createInitial()` tetap composable; jangan mengambil Person baru setelah evaluation locking dimulai.
- Correction menentukan person target, lalu reload/validate original predecessor setelah lock; tidak mengikuti successor.
- History validation existing tetap dipertahankan.
- EmployeeHistoryService sudah mengambil Person lalu active mapping sebelum predecessor/overlap reads. Hardening mengubah visibility/locking reads, bukan membaliknya menjadi history-before-master. Shared-master writer dan authoritative readers memakai fixed order yang sama; writer exclusive, reader shared current locks sesuai kebutuhan.
- Jangan rollout protocol parsial.
- Gunakan authoritative/current reads untuk validation yang membutuhkan visibility terkini.
- `useWritePdo()` saja bukan solusi REPEATABLE-READ snapshot.
- Tidak ada filesystem operation dalam retry transaction.

Cross-cutting changes pada accepted 02A dilakukan eksplisit dan wajib menjalankan relevant 02A regression/concurrency suite.

### 02C.5.1 DUK boundary dan audit evidence

DUK hardening termasuk coordination completeness pada 02C.5.1. Existing ImportService::commit melakukan checksum/staging I/O setelah ReportingPeriod/ImportBatch locks dan updateOrCreate Person dalam workbook order. Source/staging/checksum preparation harus dipindahkan sebelum lock-holding transaction; locked batch metadata dan prepared payload harus dicocokkan kembali sebelum mutation. Lock affected existing Persons ascending sebelum authoritative reread/mutation. Workbook order tetap provenance/output order, bukan lock order. New Person creation tetap menggunakan existing unique identity constraints; tidak ada merge/repair otomatis atau retry policy baru.

Audit caller produksi menemukan ManageData::saveImport memanggil ImportService::commit tanpa outer transaction. Narrow enforcement boundary: commit menolak active caller transaction sebelum filesystem/storage access atau mutation, sehingga preparation tidak berjalan sambil caller memegang DB locks. Tidak perlu broad API redesign atau perubahan UI/business behavior. Commit tests yang dibungkus RefreshDatabase/test transaction harus dipisahkan ke non-wrapping test setup untuk jalur ini; tambahkan explicit outer-transaction rejection test dan pertahankan seluruh assertion identity/reimport/rollback. Existing committed no-op dan sanitized error behavior tetap dipertahankan di luar guard tersebut.

### 02C.5.1 acceptance matrix dan harness

Berikut executable coordination gates, bukan implementasi conversion save/replacement:

| Gate | Required proof |
|---|---|
| Deterministic ordering | Batch sebelum seluruh relevant Persons ascending, lalu evaluation identities deterministic; correction Person sebelum original predecessor; Person → masters → histories, tanpa master↔history inversion |
| Nested initial creation | Outer rollback tetap benar; nested create tidak mengambil Person baru setelah evaluation locking dimulai |
| Current-read visibility | Snapshot lama tidak menjadi authoritative validation setelah coordination lock; pertahankan PakSkpImportRow shared current-read guard |
| Retry/atomicity | Exact 40001/1213 bounded retries dan current-source unique handling tetap; original predecessor dipertahankan; exhaustion/nonretryable failure tidak meninggalkan partial rows/audit |
| Existing SKP races | Import identical/different content, double commit, double correction, import-versus-correction kedua ordering, initial creation collision semantics tetap |
| Cross-writer coordination | History vs evaluation create/correction, DUK vs SKP/history/case capture, shared-master writer vs authoritative history/case capture ter-serialize pada relevant row |
| DUK vs DUK | Dua import menyentuh existing Persons yang sama dengan reversed workbook/person order: deterministic Person locks, identity/output semantics dan atomic rollback tetap |
| Mixed DUK identities | Existing/new Person input mempertahankan unique identity constraints, provenance order, dan rollback penuh; tidak ada implicit merge atau retry policy baru |
| DUK I/O boundary | Source/staging/checksum preparation tanpa held DB locks; outer transaction ditolak sebelum I/O; locked metadata/version mismatch menolak tanpa partial replacement |
| Shared masters | Relevant rows saja, fixed table/ID order; tidak ada Person fan-out atau master acquisition setelah history locking |
| Independence | Different-person operations tetap independen bila tidak berbagi master/dependency; jangan memakai global mutex |
| Responsibility boundary | Tidak ada conversion run/audit baru, conversion case-version increment, atau first-use mutation tambahan; historical inactive-level dan history lifecycle semantics tetap |
| Regression | Relevant 02A retry/concurrency, Foundation case/history/profile/master, rule first-use concurrency, 02C.1–02C.4, wider PAK, SDM Monitoring/import/template/publication/reimport; full repository regression sebelum final acceptance |

Gunakan existing disposable MariaDB independent-process harness. Adapt barrier lama yang menunggu dua worker mencapai insert menjadi observed Person/master lock waits; serialization memang menahan worker kedua sebelum insert. Jangan melemahkan final-state, independent PID/connection, uniqueness, atomic audit, rollback, atau driver-error assertions. Jangan memaksa deadlock hanya untuk membuktikan concurrency; retry detection/recovery tetap diuji melalui existing exact-driver/fault-injection cases. SQLite bukan concurrency proof. MariaDB 10.5.11 clean path dan known unrelated 10.5.10 legacy fresh-schema issue tetap dilaporkan terpisah.

Future-save lock-holder probes hanya membuktikan coordination boundary, bukan actual conversion-save correctness. Real conversion-save/replacement races tetap gate 02C.6. Tidak ada migrations/schema/Composer/permission/isolation/business-rule change; tidak ada 02C.5.2 preview/save/review orchestration atau 02C.6 replacement pada slice ini. OD-07 tetap BUSINESS PENDING dan OD-08 tetap DEFERRED.

### Retry

- Pertahankan existing bounded deadlock behavior.
- Original request/predecessor tetap.
- Run-specific retry/constraint handling ditetapkan eksplisit.
- Jangan memakai current-source collision policy untuk constraint run.
- Audit transaction composability sebelum top-level retry guard baru.

Real independent-process proof menjadi gate 02C.6.

## 15. Authorization dan audit

### OD-10 — RESOLVED for schema/auth/audit mutation boundary

Actor aktif dan `pak.access` selalu diperlukan. Authoritative mutation service sendiri authorize sebelum perubahan state melalui existing `PakEmployeeAuthorization`/service conventions; panel/policy bukan sole enforcement. Tidak menambah role atau permission Phase 02C.

| Operation | Permissions |
|---|---|
| View run | `pak.conversion.view`, `pak.case.view` |
| Preview/save/recalculate/replace | `pak.conversion.calculate`, `pak.conversion.view`, `pak.case.view`, `pak.case.update`, `pak.skp.view`, `pak.employee.view`, `pak.rule-set.view` |
| Explicit review assessment mutation | Same complete permission bundle as authoritative save/recalculate/replace |
| View source evidence | `pak.skp.view` + authorized run/case/person boundary |
| Audit history | `pak.audit.view` according to existing architecture |

Tidak menambah permission tanpa kebutuhan approved.

Approved operation events:

| Event | Operation / subject contract |
|---|---|
| `CONVERSION_RUN_SAVED` | Authoritative initial save only; subject new run, case reference required; exactly one event whether PREVIEW or initial REQUIRES_REVIEW |
| `CONVERSION_RUN_REVIEW_ASSESSED` | Explicit assessment mutation; subject existing run and case reference; covers PREVIEW → REQUIRES_REVIEW and subsequent REQUIRES_REVIEW assessment update |
| `CONVERSION_RUN_REPLACED` | Explicit replacement; subject successor run, case reference required, original predecessor in `supersedes_id` / safe bounded before-after references |

Add only `PakConversionRun` to restricted conversion-run audit subject allowlist. No period-level subject/event. Replacement does not emit a duplicate SAVED event. Read-only preview/stale assessment emits no event. Audit and mutation occur in the same transaction.

Payload stays within `before`, `after`, `reason`, `supersedes_id`: bounded safe identifiers/status/revision/issue-code summaries only. Do not include dependency manifest, full snapshots, NIP, document bodies, private paths, SQL/error traces or whole-model serialization. Assessment is not approval/finalization; remaining replay behavior stays OD-08, not implicitly resolved by event naming.

## 16. Exact arithmetic/presentation boundary

**EXISTING CONTRACT**

- RuleResolver → exact decimal strings.
- ConversionInput → validated immutable input.
- ConversionCalculator → BigRational.
- Total → sum exact lines.
- ConversionRoundingPolicy → disposable machine/human strings.

Tidak redesign 02B.

BR-KONV-004 tetap APPROVED. PREVIEW_ONLY adalah lifecycle boundary Phase 02.

## 17. Golden fixtures

| Months | Factor | Coefficient | Exact | Human |
|---:|---:|---:|---|---|
| 2 | 1 | 25 | 25/6 | 4,167 |
| 12 | 1 | 25 | 25 | 25 |
| 12 | 1 | 25 | 25 | 25 |
| 10 | 1 | 25 | 125/6 | 20,833 |
| Total | | | **75** | **75** |

Tidak memasukkan AK Dasar, Integrasi, old conversion, atau cumulative 175. Tidak membuat mapping JF Nurmaulida.

**Fixture ownership 02C.5.2.A:** TC-PAK-001/037 Nurmaulida tetap numeric-only business Golden untuk exact lines `25/6`, `25/1`, `25/1`, `125/6` dan total `75/1`: exact arithmetic, no rounded-line summation, presentation separation, no AK Dasar double counting. Fixture itu bukan bukti SK/TMT Jabatan SK, real history/provenance, OD-04 continuity, OD-13 evidence, atau actual position/level mapping; verified mapping tetap pending dan tidak boleh dilengkapi dengan documentary evidence rekaan.

Preview integration memakai fixture terpisah yang eksplisit synthetic/non-real: synthetic person/case, verified non-superseded OD-04-compatible histories, OD-13-complete synthetic SK number/reference dan accepted tmt_start, position/level mappings, evaluations, rule set/children serta dependencies sesuai accepted contracts. Fixture tersebut boleh mereproduksi empat exact lines dan total `75/1` untuk menguji orchestration OD-03/source/OD-04/OD-13/regime/child rules/calculator/manifest/fingerprint. Itu hanya integration arithmetic expectation, bukan factual Nurmaulida/real-employee evidence. Jangan melemahkan OD-13 atau mengubah original numeric Golden. Rincian ownership berada pada [TC-PAK-037](../test-cases.md#tc-pak-037--golden-nurmaulida-numeric-only); tidak menambah business rule/OD atau memperluas scope preview.

Fixtures tambahan:

- Annual wins.
- Q2 eligible untuk Jan–May; Q3 tidak.
- Q4 eligible untuk Jan–Oct.
- Multiple annual mengikuti history proof/TMT.
- TMT terbaru berlaku whole-month.
- Tiga exact `25/12` menghasilkan `25/4`, bukan jumlah rounded lines.

## 18. Automated test matrix

| Slice | Required coverage |
|---|---|
| 02C.1 | Explicit OFFICIAL/CURRENT/type eligibility, historical exclusions tanpa mutation, annual priority, quarterly boundary, source_date ignored, proof order, superseded-link review, ambiguity/no tie-break |
| 02C.2 | Canonical months, inclusive count, cross-year/leap year, gap/overlap, exact case range, valid subset, starts-before, ends-after, fully outside, invalid/reversed range, approved partial-period behavior |
| 02C.3 | TMT awal/tengah/akhir, multiple changes, missing/equal-TMT ambiguity, inactive master, corrected history, non-JF/regime review |
| 02C.4 | Schema/FK/unique, contiguous-line guards, exact persistence, NULL total, immutable snapshot fields, permitted lifecycle-only mutation, upgrade/rollback |
| 02C.5 | Authorization, preview read-only, locked-case range revalidation, no implicit case-period update, server recomputation, stale fingerprint/version, rule equality, OD-11 validity/precedence/outcomes sesuai TC-PAK-042, atomic rollback, complete coordination protocol |
| 02C.6 | Explicit review, replacement chain, stale predecessor, replay decision, real races, no partial state |

Required MariaDB races:

- Two saves same case.
- Two replacements same predecessor.
- Source correction versus save.
- Annual insertion versus save.
- History mutation versus save.
- Case period/rule-set change versus save.
- Rule first-use/child-write regression.
- Relevant existing 02A import/import, correction/correction, import/correction scenarios.

Independent processes/connections, bounded timeouts, and fresh final assertions wajib. SQLite bukan concurrency proof.

## 19. Migration/deployment compatibility

- Additive migrations; runs sebelum periods.
- Reverse dependency rollback.
- FK RESTRICT; VARCHAR/application enums.
- Tidak mengubah old migrations.
- Tidak backfill histori/JF/source secara spekulatif.
- Tidak seed active regulation.
- Migration dependency tests memakai explicit paths.
- Production tetap MariaDB 10.5.10.
- Known fresh-schema `42S22/1054` legacy issue dipisahkan dari conversion correctness.
- Reuse upgrade harness; fallback 10.5.11 dilaporkan sebagai versi berbeda.

## 20. Delivery slices dan gates

| Slice | Gate |
|---|---|
| **02C.1** | OD-01/02 dan active eligibility diterapkan; DTO/tests lulus |
| **02C.2** | OD-03 RESOLVED; implementasi mengikuti full-month-only contract dan TC-PAK-039; coverage dan requested-range guards lulus |
| **02C.3** | OD-04 RESOLVED; continuity-first menghasilkan assignment/provenance atau structured issues; position/proof integration dan TC-PAK-040 lulus; tidak mengimplikasikan perubahan source/schema |
| **02C.4** | DTO final; OD-09 RESOLVED dan OD-10 schema/auth/audit mutation boundary RESOLVED; implement approved schema contract dan hanya items `[02C.4]` pada umbrella TC-PAK-041; planned executable gates `[02C.5]`/`[02C.6]` tidak memblok acceptance 02C.4 |
| **02C.5** | Terapkan OD-10 resolved boundary dan OD-11 RESOLVED sesuai TC-PAK-042; 02C.5.2 initial-only boundary, OD-12, dan OD-13 berlaku; OD-08 tetap DEFERRED, bukan prerequisite initial-only API; review-assessment mutation di luar 02C.5.2; 02C.5.1 lengkap sebelum acceptance 02C.5.2 |
| **02C.6** | Replacement/replay final; required MariaDB races dan relevant 02A regression lulus |

Tidak ada slice ketujuh. 02C.5.1/02C.5.2 hanya decomposition delivery internal.

## 21. Acceptance criteria

- Setiap counted month memiliki satu authoritative source dan position.
- Source eligibility/priority/proof mengikuti resolved contracts.
- Requested range tidak keluar dari locked PakCase period.
- Unresolved coverage tidak menghasilkan total lengkap palsu.
- Save re-resolve/recalculate server-side.
- Coordination protocol berlaku pada seluruh authoritative dependency writers.
- Stale detection tidak mengubah calculation snapshot.
- Review mutation eksplisit, authorized, dan audited.
- Replacement tidak bercabang atau mengalihkan predecessor.
- Snapshot/revision identity immutable; lifecycle changes hanya melalui service.
- Exact total tetap authoritative.
- Regression dan real concurrency gates lulus.
- Tidak ada UI atau finalization/penetapan.

## 22. Decision register

| ID | Status | Gate/batas |
|---|---|---|
| OD-01 Quarterly relevance | **RESOLVED** | Quarter-start ≤ requested end month; annual priority |
| OD-02 Evaluation–history proof | **RESOLVED** | Single annual tanpa FK prerequisite; explicit link lalu exact reconstruction |
| OD-03 Partial requested range | **RESOLVED** | Full-month-only; partial boundary menghasilkan PARTIAL_MONTH_REQUIRES_REVIEW tanpa complete counted-month list/count; wajib diikuti 02C.2 |
| OD-04 History end/gap dalam bulan | **RESOLVED** | Inclusive interval; continuity/no-overlap seluruh bulan sebelum latest-TMT whole-month selection; gate 02C.3 mengikuti contract di atas |
| OD-05 Person coordination | **RESOLVED technical contract** | Complete protocol sebelum acceptance 02C.5; real proof 02C.6 |
| OD-06 Stale/review | **RESOLVED direction** | Read-only assessment + explicit authorized mutation |
| OD-07 Annual-late replacement | **BUSINESS PENDING** | BR-SKP-008 tetap; no auto replacement |
| OD-08 Replay/idempotency | **DEFERRED within 02C** | Finalisasi 02C.5/02C.6 |
| OD-09 Persistence detail | **RESOLVED** | Runs + periods; immutable manifest/snapshots, TEXT exact rational, approved projection/version schema; gate 02C.4 |
| OD-10 Audit/review authorization | **RESOLVED for schema/auth/audit mutation boundary** | Existing permission bundle; assessment fields/event, explicit lifecycle transitions and atomic bounded audit; no approval/finalization |
| OD-11 Rule validity behavior | **RESOLVED** | Explicit case rule set; known inclusive validity covers entire request; NULL = unknown; failure diagnostics-only tanpa angka/total, conditional review save; precedence dan child outcomes mengikuti section 11; executable gate 02C.5/TC-PAK-042 |
| OD-12 Canonical LEGACY outcome | **RESOLVED** | Authoritative TMT Jabatan SK < 2023-01-01 → LEGACY_REGIME_REQUIRES_REVIEW; affected line non-numeric, parent total NULL, conditional diagnostic save; no current-rule lookup or AK Integrasi consumption; section 11 |
| OD-13 TMT Jabatan SK evidence | **RESOLVED** | Selected same-person verified/non-superseded OD-04 history + tmt_start + nonblank sk_number/source_ref proves effective TMT Jabatan SK for regime only; insufficient proof → REGIME_EVIDENCE_REQUIRES_REVIEW; section 11 |
| Active source eligibility | **RESOLVED invariant** | CURRENT + OFFICIAL + ANNUAL/Q1–Q4 |
| Requested-range containment | **RESOLVED invariant** | Valid non-empty subset/equal authoritative case period |
| Calculation snapshot immutability | **RESOLVED invariant** | Lifecycle metadata saja yang boleh dimutasi melalui authorized service |
| 02C.5 delivery decomposition | **RESOLVED delivery note** | Coordination hardening lalu preview/authoritative save; bukan slice tambahan |

Dokumen ini merupakan planning-only implementation contract. Approval plan ini tidak mengotorisasi perubahan source, migration, test executable, atau implementasi Phase 02C di luar instruksi slice berikutnya dan gate yang telah ditetapkan.
