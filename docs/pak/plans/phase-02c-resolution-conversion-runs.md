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

BR-REGIME-001 tetap berlaku. LEGACY/AK Integrasi tidak dihitung atau ditambahkan oleh conversion engine. Kebutuhan mekanisme lain menghasilkan review.

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

DDL final diturunkan setelah DTO 02C.1–02C.3 stabil.

### `pak_conversion_runs`

Kandidat kelompok data:

- Case, Person, explicit rule set.
- Requested calculation context.
- Server revision number dan nullable predecessor.
- Lifecycle status.
- Nullable exact total numerator/denominator.
- Immutable dependency manifest/fingerprint.
- Original calculation issues/trace.
- Review-assessment metadata terpisah.
- Actor/time/revision reason/supersession provenance.
- Presentation profile/version.

### `pak_conversion_periods`

- Run ID dan deterministic line number.
- Contiguous start/end dan integer month count.
- Evaluation/history/functional-level references.
- Predicate/coefficient rule references.
- Exact operands.
- Original coverage dan applied range.
- Immutable snapshots/provenance/trace.
- Nullable exact line numerator/denominator.
- Original line issues.

### Canonical month list

**RESOLVED**

Month list berada pada DTO/dependency manifest.

Tidak wajib menyimpan month-list tambahan per line jika contiguous start/end/count cukup. Redundansi memerlukan kebutuhan audit/schema yang jelas.

Noncontiguous months menjadi line berbeda. Guard memastikan boundaries/count konsisten.

### Exact persistence

- Numerator/denominator berupa exact integer strings.
- Denominator positif.
- Pasangan NULL konsisten.
- Bulan tak terhitung menyebabkan total keseluruhan NULL.
- Human/machine presentation direcompute dari exact values dan policy version.
- Tidak menjadikan rounded values authoritative.

### Constraints

- FK RESTRICT.
- Unique case + revision.
- Unique nullable predecessor.
- Unique run + line number.
- No overlapping counted months.
- Person/source/history/rule-set consistency.
- No delete atau calculation-snapshot rewrite.

**OD-09:** final widths, manifest layout, indexes, dan current-run projection diputuskan setelah DTO final.

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
- Reviewed actor/time.
- Supersession metadata.

Lifecycle mutation tidak pernah mengubah calculation snapshot.

Original issue tidak dihapus atau ditulis ulang ketika assessment baru dibuat. Run dengan sebagian line terhitung dapat menyimpan diagnostics, tetapi total lengkap tetap NULL.

## 11. 02C.5 — Conversion Run Orchestration

### Components

- `ConversionService`.
- Preview/input/result DTOs.
- Versioned preview fingerprint builder.
- Integration tests.

### Pipeline

1. Authorize.
2. Load authoritative case.
3. Validate requested range dan containment.
4. Enumerate months/year partitions server-side.
5. Collect eligible CURRENT sources.
6. Resolve position per bulan.
7. Finalize source selection/proof.
8. Bentuk contiguous calculation lines.
9. Periksa regime, suitability, dan rule validity.
10. RuleResolver.
11. ConversionInput.
12. ConversionCalculator dan exact total.
13. ConversionRoundingPolicy.
14. Preview DTO/dependency fingerprint.

Orchestration menyatukan source dan position results; kedua resolver tidak saling memanggil secara circular.

### Preview

- Read-only.
- Tidak membuat run/audit.
- Tidak menandai first-use.
- Case tanpa rule set boleh menghasilkan diagnostics, tetapi tidak dapat save run.

### Authoritative save

- Authorize ulang.
- Lock/reload case.
- Recheck DRAFT, not finalized, expected version.
- Validate requested range terhadap locked case.
- Ikuti full coordination protocol.
- Re-resolve/recalculate.
- Run rule set sama dengan locked case rule set.
- Preview fingerprint/dependency berubah → reject dan preview ulang.
- Persist run/lines/audit dan increment case version atomik.
- Tidak mengubah case period secara implisit.

Pisahkan semantic fingerprint dari optimistic version agar save sendiri tidak membuat run langsung stale.

### Delivery decomposition — RESOLVED

02C.5 dapat diserahkan secara internal sebagai:

- **02C.5.1 — Cross-writer Person Coordination Hardening**
- **02C.5.2 — Conversion Preview + Authoritative Save**

Ini hanya decomposition commit/review, bukan slice ketujuh.

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

Replay/idempotency diselesaikan pada 02C.5/02C.6 sebelum API final. Jangan menjanjikan idempotent success atau menyalin SKP COMMITTED fast path tanpa keputusan.

## 14. Concurrency dan lock ordering

### OD-05 — RESOLVED technical contract

Person row adalah coordination lock per person, bukan global/table/advisory lock.

| Operation | Lock order |
|---|---|
| Conversion save | Case → Person → rule set → predecessor run → evaluations deterministic → histories deterministic |
| SKP batch commit | Batch → Person IDs ascending → evaluation identities deterministic |
| Evaluation correction | Person → original predecessor evaluation |
| Initial/current creation | Person → evaluation identity |
| Position history mutation | Person → history |

### Implementation requirements

- Semua authoritative dependency writers wajib mengikuti protocol.
- SKP commit mengunci seluruh relevant Person IDs ascending sebelum evaluation mutation.
- Nested `createInitial()` tetap composable; jangan mengambil Person baru setelah evaluation locking dimulai.
- Correction menentukan person target, lalu reload/validate original predecessor setelah lock; tidak mengikuti successor.
- History validation existing tetap dipertahankan.
- Jangan rollout protocol parsial.
- Gunakan authoritative/current reads untuk validation yang membutuhkan visibility terkini.
- `useWritePdo()` saja bukan solusi REPEATABLE-READ snapshot.
- Tidak ada filesystem operation dalam retry transaction.

Cross-cutting changes pada accepted 02A dilakukan eksplisit dan wajib menjalankan relevant 02A regression/concurrency suite.

### Retry

- Pertahankan existing bounded deadlock behavior.
- Original request/predecessor tetap.
- Run-specific retry/constraint handling ditetapkan eksplisit.
- Jangan memakai current-source collision policy untuk constraint run.
- Audit transaction composability sebelum top-level retry guard baru.

Real independent-process proof menjadi gate 02C.6.

## 15. Authorization dan audit

Actor aktif dan `pak.access` selalu diperlukan.

| Operation | Permissions |
|---|---|
| View run | `pak.conversion.view`, `pak.case.view` |
| Preview/save/recalculate | `pak.conversion.calculate`, `pak.conversion.view`, `pak.case.view`, `pak.case.update`, `pak.skp.view`, `pak.employee.view`, `pak.rule-set.view` |
| View evidence | `pak.skp.view` + record/person boundary |
| Explicit review mutation | Finalisasi melalui OD-10 |

Tidak menambah permission tanpa kebutuhan approved.

Proposed audit events tetap menunggu OD-10:

- `CONVERSION_RUN_SAVED`
- `CONVERSION_RUN_REPLACED`
- `CONVERSION_RUN_REVIEW_REQUIRED`

Subject run ditambahkan secara terbatas ke allowlist. Payload tetap existing top-level whitelist, dengan nested safe IDs/status/revision/reason/issue codes.

Tidak ada whole-model serialization, NIP, raw workbook, private paths, atau exception traces.

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
| 02C.5 | Authorization, preview read-only, locked-case range revalidation, no implicit case-period update, server recomputation, stale fingerprint/version, rule equality, atomic rollback, complete coordination protocol |
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
| **02C.4** | DTO final; OD-09 schema dan bagian audit OD-10 disepakati |
| **02C.5** | OD-10/11 selesai; OD-08 API behavior ditetapkan; 02C.5.1 lengkap sebelum acceptance 02C.5.2 |
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
| OD-09 Persistence detail | **DEFERRED within 02C** | Setelah DTO 02C.1–02C.3 final |
| OD-10 Audit/review authorization | **OPEN DECISION** | Selesai sebelum 02C.4/02C.5 |
| OD-11 Rule validity behavior | **OPEN DECISION** | Selesai sebelum 02C.5 numeric orchestration |
| Active source eligibility | **RESOLVED invariant** | CURRENT + OFFICIAL + ANNUAL/Q1–Q4 |
| Requested-range containment | **RESOLVED invariant** | Valid non-empty subset/equal authoritative case period |
| Calculation snapshot immutability | **RESOLVED invariant** | Lifecycle metadata saja yang boleh dimutasi melalui authorized service |
| 02C.5 delivery decomposition | **RESOLVED delivery note** | Coordination hardening lalu preview/authoritative save; bukan slice tambahan |

Dokumen ini merupakan planning-only implementation contract. Approval plan ini tidak mengotorisasi perubahan source, migration, test executable, atau implementasi Phase 02C di luar instruksi slice berikutnya dan gate yang telah ditetapkan.
