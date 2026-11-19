# Phase 02D — UI / Evidence / Audit Presentation

**Status: FROZEN FOR IMPLEMENTATION — berlaku setelah commit dokumentasi plan/freeze ini, dengan gate bersyarat pada section 4 dan 5.** Checkpoint plan ini documentation-only; bukan acceptance implementasi 02D, status FINAL, UAT selesai, atau deployment terverifikasi. Implementasi dilakukan dalam delivery terpisah setelah plan diterima.

## 1. Baseline dan sumber kontrak

- Branch: `feature/pak-phase-02`; baseline plan `edfd875`, sekaligus formal closure Phase 02C. Technical acceptance Phase 02C: `de40fef`.
- Phase 02C **FINAL** dan tidak dibuka kembali. 02D mengonsumsi backend accepted 02A (SKP/source foundation), 02B (rules/calculation), dan 02C (resolution/conversion runs).
- Rujukan: [umbrella Phase 02](phase-02-skp-conversion.md), [Phase 02C frozen plan](phase-02c-resolution-conversion-runs.md), [business rules](../business-rules.md), [decisions](../decisions.md), dan [test cases](../test-cases.md).
- Nama API, permission, DTO, state, dan event di bawah mengikuti source accepted pada baseline, bukan nama service aspiratif dalam checkpoint awal umbrella. UI tidak boleh mengimplementasikan ulang matematika, pemilihan sumber, atau lifecycle backend.

## 2. Tujuan dan batas scope

02D menyediakan SKP preparation/preview/commit/history; private evidence upload/presentation/download; conversion preview/initial save; saved run, calculation lines, provenance, revision comparison; read-only stale assessment; explicit review dan replacement; audit presentation; serta dashboard **Perlu Pemeriksaan**.

Di luar scope: matematika konversi baru, source-selection semantics baru, review lifecycle baru, akumulasi, KP/KJ, general warning engine, approval/finalization/penetapan PAK, employee self-service portal, production deployment dan UAT. **PAK Ditetapkan tetap placeholder.** Perubahan ini harus menjaga `/admin`, dashboard publik, dan workflow SDM/DUK existing.

## 3. Internal delivery dan urutan

| Delivery | Scope | Dependency / acceptance gate |
| --- | --- | --- |
| **02D.1 — SKP Import & Import History UI** | Upload, preparation tersimpan, commit, history, evaluasi/correction navigation | Final acceptance memerlukan representative client workbook dan accepted production adapter (section 4). |
| **02D.2 — Private Evidence UI** | Attachment upload/presentation/authorized download | Sebelum implementasi, representation persistence/lifecycle harus dibekukan; schema/model baru memerlukan approval eksplisit (section 5). |
| **02D.3 — Conversion / Assessment / Review / Replacement UI** | Konsumsi API accepted, detail/provenance/comparison dan explicit actions | Dapat dimulai pertama setelah plan diterima; tidak bergantung pada production workbook adapter atau evidence persistence. |
| **02D.4 — Audit Presentation + Dashboard Review** | Safe event/subject presentation, latest-run metric dan destination | Normalnya setelah 02D.3 agar destination/link sudah final dan authorized. |

Nomor adalah identitas internal delivery, bukan kewajiban urutan numerik. Tidak ada top-level Phase 02 baru, 02D.5, atau perubahan decomposition 02C. D1/D2 tidak menghalangi D3; link evidence yang belum tersedia tidak boleh direkayasa menjadi public URL.

## 4. 02D.1 — SKP import dan history

Reuse `SkpImportPreparationService::prepare()/prepareBatch()`, `SkpImportCommitService::commit()`, `SkpPersonMatcher`, `SkpFingerprintSerializer`, `PerformanceEvaluationService`, `PakSkpImportBatch`, `PakSkpImportRow`, dan storage/provenance existing. `PerformanceEvaluationService::correct()` tetap explicit correction dengan provenance dan reason yang dipersyaratkan backend.

UI mengumpulkan authorized upload, menampilkan preparation result tersimpan, batch/row status, issues/results, commit action, import history dan link evaluasi hasil; correction navigation hanya jika existing contract mengizinkan. Preparation **menulis provenance/batch/row**, bukan read-only preview. Gunakan state existing, tanpa state baru. Jangan auto-correct `CONFLICT` atau menyelesaikan `IDENTITY_AMBIGUOUS` dengan arbitrary browser person selection.

**Production workbook adapter gate:** source accepted menyediakan `SkpSourceAdapter` interface dan synthetic test adapter; belum merupakan accepted production workbook mapping. Mapping tidak boleh ditebak. Sanitized representative workbook dengan struktur client sebenarnya wajib memverifikasi sheet/header, struktur record, identity person, source reference, source record identifier, evaluation type, performance year, coverage, predicate, relevant dates, dan provenance.

Klasifikasi: **PLAN PREREQUISITE untuk acceptance 02D.1; NON-BLOCKING untuk plan freeze dan implementasi 02D.3.** Tidak ada adapter atau workbook mapping yang diimplementasikan/disetujui oleh dokumen ini.

Acceptance D1: authorized persisted preparation/commit/history menggunakan backend existing, states dan conflict/identity handling sesuai kontrak, focused tests section 13 lulus, serta workbook/adapter gate dipenuhi. Bukan automatic correction atau revision policy baru.

## 5. 02D.2 — Private evidence dan design gate

**Import workbook provenance berbeda dari evaluation/document evidence attachment.** `SkpSourceStorage` dan batch provenance bukan general attachment representation. Baseline belum menyediakan accepted dedicated evidence attachment representation.

**PRE-IMPLEMENTATION DESIGN GATE:** freeze terpisah harus menetapkan attachment ownership/linkage, hubungan evaluation revision, immutability, allowed MIME, maximum size, checksum, private object storage, authorized upload/download, replacement/version behavior, failure compensation, orphan cleanup, safe audit metadata, dan retention. Jangan menganggap versi evaluasi baru otomatis memindahkan/menghapus evidence lama.

Default UX dari umbrella tetap **PDF/JPEG/PNG, maksimum 10 MiB**, private storage, random object name, checksum, tanpa public URL, record-authorized download. Detail persistence/lifecycle belum disetujui hanya dengan default tersebut. Tidak ada migration/schema approval dalam plan ini. Jika schema/model changes diperlukan, approval eksplisit harus diperoleh sebelum implementasi 02D.2.

Download contract:

- Route menerima bounded record identity saja; tidak menerima filesystem path atau object key dari browser.
- `auth`, `EnsureUserIsActive`, `pak.access`, `pak.skp.view`, dan record/person authorization wajib sebelum akses object.
- Server menyelesaikan storage object dari record authorized; private stream response dengan filename/content headers aman.
- Foreign/missing record fail closed, tanpa path disclosure atau public storage URL.
- Akses satu evaluation **tidak** memberi akses multi-person batch workbook. Bila batch download hendak diekspos, perlu plan decision terpisah; tidak implicit dalam evidence download.

Acceptance D2: design gate disetujui, private upload/download dan immutable provenance memenuhi representation tersebut, tests type/size/authorization/failure compensation lulus. Tidak membangun public file browser atau free-form path endpoint.

## 6. 02D.3 — API dan presentation conversion

Gunakan API accepted beserta request DTO existing:

| Operasi | API / request |
| --- | --- |
| Preview | `ConversionService::preview()` / `ConversionPreviewRequest` |
| Initial authoritative save | `ConversionService::saveInitial()` / `ConversionSaveRequest` |
| Read-only stale assessment | `ConversionAssessmentService::assess()` / `ConversionAssessmentRequest` |
| Explicit review | `ConversionReviewService::assessReview()` / `ConversionReviewRequest` |
| Explicit replacement | `ConversionService::replace()` / `ConversionReplaceRequest` |

Tidak ada API baru `savePreview()`. `ConversionRunLifecycle` dan immutable run/period models tetap backend boundary, bukan generic model update dari Filament.

Case conversion page menyediakan requested range, read-only preview, issues, calculation lines, exact rational dan human display, source/position/rule provenance, initial save, saved run details, revision chain/comparison, stale assessment, explicit review reason/action, dan explicit replacement reason/action. Gunakan display/rounding accepted, bukan floating-point recalculation UI. Unavailable total harus tetap terlihat unavailable, bukan diubah menjadi nol.

### Server authority

Browser **bukan authority** untuk predicate factor, coefficient, counted months, position/functional level, selected authoritative SKP, rule identity di luar accepted case context, AK line/total, status, revision number, predecessor retargeting, atau dependency manifest. Submit hanya accepted user inputs dan optimistic identifiers/tokens sesuai DTO. Backend re-authorize, reload, recalculate/reassess, validate hashes/version/sequence/token, lalu persist. Disabled/hidden/locked Livewire fields adalah presentation controls, bukan pengganti server authorization/validation.

Stale rejection harus ditampilkan sebagai kebutuhan preview/assessment baru, bukan mengubah token otomatis lalu mengulangi mutation tanpa intent baru. Jangan membungkus service dalam transaksi/retry UI yang mengubah accepted transaction ownership atau retry semantics. Technical error bukan assessment UNASSESSABLE; tampilkan pesan aman tanpa SQL/trace.

### Lifecycle dan comparison

Hanya status run **PREVIEW, REQUIRES_REVIEW, SUPERSEDED**. Tidak ada FINAL, APPROVED, DITETAPKAN, approval action, atau finalization action. Explicit review adalah assessment metadata, bukan business approval. Replacement explicit; tidak ada annual-arrival auto replacement, auto recalculation, replay/idempotent-success atau return-existing-run behavior baru.

Comparison adalah presentation, bukan lifecycle authority: saved revision N versus N+1 dalam case yang sama, atau saved immutable run versus current read-only assessment. Dilarang arbitrary cross-case comparison dan client reconstruction calculation. Tampilkan bounded differences: status, requested range, exact/unavailable total, issue codes, selected source/position/rule provenance, assessment comparison/changed_groups. Jangan dump raw manifest/whole model JSON secara default. Detailed field projection dibekukan saat slice review tanpa menambah authority. Assessment display tetap zero-write; historical run tidak ditarget ulang ke successor.

Acceptance D3: seluruh action memakai API/DTO accepted, exact target dan stale handling terbukti, immutable evidence/revision chain terjaga, permission/tampering tests lulus. Tidak bergantung pada D1/D2 yang belum accepted.

## 7. Authorization dan ownership

Permission existing tetap; tidak ada role/permission/seeder baru yang diotorisasi plan ini. Semua surface memerlukan active actor dan `pak.access`.

| Surface | Boundary tambahan |
| --- | --- |
| SKP/history/evidence read | `pak.skp.view` serta record/person boundary |
| Preparation/commit/correction/upload | `pak.skp.manage`, read permission bila menyajikan data, dan existing service authorization |
| Case/run presentation | `pak.case.view`, `pak.conversion.view`, nested ownership; provenance detail mengikuti permission domain terkait |
| Conversion preview/mutation/assessment API | **Complete `ConversionService::PERMISSIONS`**, termasuk read-only assessment |
| Employee / rule detail navigation | `pak.employee.view` / `pak.rule-set.view`; jangan membocorkan lewat link/detail tanpa permission |
| Audit | `pak.audit.view` |

Complete conversion bundle pada baseline: `pak.conversion.calculate`, `pak.conversion.view`, `pak.case.view`, `pak.case.update`, `pak.skp.view`, `pak.employee.view`, `pak.rule-set.view`. Jangan mengurangi bundle untuk mempermudah UI.

Resource visibility, page access, Livewire actions, download, dan query/data presentation harus server-enforced; hiding navigation tidak cukup. Daftarkan surface baru sesuai `InternalModuleAccess` existing. Admin SDM tidak otomatis mendapat PAK; tidak membuat employee portal semantics.

Verifikasi setiap nested identity: run milik case; evaluation/history milik person; review exact case/run; replacement exact predecessor milik case dan accepted lifecycle. Arbitrary browser IDs tidak dipercaya. Record policies/authorization components boleh ditambah mengikuti convention, tanpa mengubah permission model.

## 8. Rule UI boundary

Reuse `PakRuleSetResource`, existing rule permissions, `ConversionRuleService`, dan version copy backend `PakRuleSetService::copyVersion()`. Child predicate/coefficient rules sudah ada di backend; jangan menganggap metadata-only UI baseline sudah mengelola semua children. Used-rule lock tetap berlaku; copy/new version adalah remedy, bukan reopen historical version.

Detailed child-rule editor UX adalah **PLAN DETAIL TO FREEZE BEFORE THE SLICE TOUCHES PakRuleSetResource**. Hal ini tidak memblokir conversion presentation D3, tidak mengotorisasi rule semantics baru, dan tidak otomatis memperluas scope resource.

## 9. 02D.4 — Audit presentation

Reuse `PakEventResource`, `ActivityDetailPresenter`, dan `PakAuditService`. Audit source event codes, bukan membuat event demi UI. Minimum existing Phase 02 support:

- `SKP_IMPORT_COMMITTED`, `PERFORMANCE_EVALUATION_CORRECTED`;
- `PREDICATE_RULE_CREATED`, `PREDICATE_RULE_UPDATED`, `COEFFICIENT_RULE_CREATED`, `COEFFICIENT_RULE_UPDATED`, `RULE_SET_COPIED`;
- `CONVERSION_RUN_SAVED`, `CONVERSION_RUN_REVIEW_ASSESSED`, `CONVERSION_RUN_REPLACED`.

Subject labels harus mendukung accepted Phase 02 models termasuk `PakConversionRun`. Preserve labels/event history existing; gunakan whitelist safe presentation fields, termasuk pada technical-detail view. Jangan memperluas audit payload. HTML harus escaped. Jangan menampilkan full NIP kecuali eksplisit diperlukan dan authorized, private paths/object keys, binary data, whole employee snapshots/manifests, credentials/tokens, SQL, atau exception traces.

## 10. Dashboard Perlu Pemeriksaan

Satu **latest revision per case**; hanya hitung jika latest run berstatus `REQUIRES_REVIEW`. Historical/SUPERSEDED tidak menggandakan count. Bounded query object boleh dibuat untuk memakai semantics yang sama pada metric dan destination list.

Sebelum review-count query, wajib `pak.case.view` dan `pak.conversion.view` (di dalam active/pak.access boundary). Tanpa permission tampil **“—”** dan **jangan jalankan query**. Authorized empty tampil **“0”**. Link dan destination list memakai query semantics serta authorization identik, dengan record detail tetap diperiksa. Metric bukan eligibility/general warning/approval/finalization. **PAK Ditetapkan tetap placeholder.**

Acceptance D4: exact safe event/subject labels, latest-only count dan destination authorized, denied zero-query, escaping dan regression lulus. Normalnya memerlukan destination final D3.

## 11. File-impact forecast (bukan implementation approval)

| Area | Klasifikasi dan perkiraan |
| --- | --- |
| `app/Filament/Pak/Pages/Dashboard.php` | EXISTING — likely modify metric/link |
| `app/Filament/Pak/Resources/PakCaseResource.php` | EXISTING — likely modify conversion integration/navigation |
| `app/Filament/Pak/Resources/PakEventResource.php`, `app/Filament/Pak/Support` | EXISTING — likely modify safe presentation |
| `app/Filament/Pak/Resources/PakRuleSetResource.php` | EXISTING — conditional setelah child-rule UX freeze |
| `app/Filament/Support/InternalModuleAccess.php` | EXISTING — likely modify authorized surface registry |
| `resources/views/filament/pak` | EXISTING / NEW — bounded views/components |
| `routes/web.php`, `app/Http/Controllers` | EXISTING route / NEW private evidence controller setelah D2 gate |
| `app/Filament/Pak` | NEW — SKP/import pages/resources dan conversion pages/components |
| `app/Domain/Pak/Queries` | NEW — bounded authorized queries bila existing query tidak cukup |
| Policies/authorization components | NEW/EXISTING sesuai convention dan record boundary, tanpa permission baru |
| `tests` | NEW/EXISTING — focused UI/authorization/integration regressions |
| Accepted domain services/calculators/DTOs | NO SEMANTIC CHANGE EXPECTED; reuse accepted API |
| Models/schema/migrations | NO CHANGE ASSUMED; D2 representation hanya setelah approval eksplisit jika perlu |
| Permissions/seeders/Composer | NO CHANGE EXPECTED |
| Finalization/approval/portal/deployment | OUT OF SCOPE implementasi 02D |

Forecast tidak mengotorisasi refactor luas. Setiap implementation slice harus mempersempit actual file list sebelum perubahan; production contract defect dilaporkan, bukan diam-diam diperbaiki dalam UI.

## 12. Pending rules dan safe presentation

**OD-07 / BR-SKP-008 tetap BUSINESS PENDING**: kapan/bagaimana annual-late memerlukan historical replacement belum diputuskan. Explicit assessment/replacement adalah mekanisme, bukan trigger. Tidak ada automatic update/recalculation/successor/replacement.

**OD-08 tetap DEFERRED**, carry-forward/non-blocking; tidak ada replay/idempotent-success baru. BR-PERIOD-003, BR-APPOINT-008, BR-KJ-005 dan BR-BAL-003 tidak diselesaikan. UI tidak mengasumsikan universal three-year policy, appointment terminology, failed-test reuse, atau SK/TMT activation. Bila relevan, tampilkan batas/pending decision secara aman; jangan menyediakan action yang bergantung pada keputusan belum accepted. Pending rules ini tidak menghalangi konsumsi API accepted dalam scope ini.

## 13. Test contract dan acceptance per delivery

| Delivery | Focused automated tests wajib |
| --- | --- |
| 02D.1 | Filament/Livewire rendering; authorization/inactive; upload → persisted preparation → commit; duplicate/conflict/identity ambiguity; forged/tampered state; import history dan authorized evaluation/correction links. |
| 02D.2 | MIME/size; private storage; authorized download; foreign-person/inactive/direct URL denial; traversal resistance; immutable evidence; checksum/provenance; failure compensation/orphan handling sesuai approved design. |
| 02D.3 | Rendering/permission matrix; forged factor/coefficient/month/source/total/status/revision; preview/save; stale case/hash; assessment zero-write; review sequence/token mismatch; wrong predecessor/case replacement; revision comparison; no retarget/replay. |
| 02D.4 | Event/subject labels; safe payload whitelist; HTML escaping; latest-only/historical exclusion; denied zero-query; “—” versus “0”; destination link authorization. |

Reuse regression patterns `PanelAuthorizationTest`, `PresentationPolishTest`, `ActivityDetailPresentationTest`; SKP preparation/commit/storage/matcher tests; conversion preview/initial save/assessment/review/replacement/lifecycle tests; dan foundation/rule tests sesuai surface yang disentuh. Backend tests bukan pengganti direct Livewire/action/download tampering tests. Focused regression setiap slice wajib, termasuk shared `/admin`/SDM bila disentuh. Plan ini tidak mencatat test execution baru.

## 14. Phase 02E boundary

Tetap 02E: whole Phase 02 full regression; isolated upgrade/fresh-schema compatibility; final MySQL concurrency/race matrix; UAT; final verification/result documentation; deployment verification sesuai workflow proyek. Focused regression normal tetap wajib selama D1–D4. Evidence disposable concurrency 02C tidak menjadi klaim deployment atau UAT 02D.

## 15. Freeze dan tindak lanjut

Setelah commit dokumentasi plan/freeze ini, plan membekukan scope/presentation/security contract, bukan implementasi. Workbook/adapter acceptance D1 dan approved persistence/lifecycle D2 adalah conditional gates yang tetap terbuka. D3 boleh menjadi implementation slice pertama setelah plan diterima, lalu D4; D1/D2 berjalan saat gate masing-masing terpenuhi tanpa renaming slice.

Historical umbrella wording tetap provenance waktu approval awal. Checkpoint terkini mengakui 02A–02C implemented/accepted, 02C FINAL pada `edfd875`, dan 02D berada pada planning checkpoint. Nama `savePreview()` lama tidak boleh dipakai sebagai target API; current initial-save API adalah `saveInitial()`, replacement terpisah `replace()`. Tidak ada perubahan business rule, source, tests, schema, route, permission, atau deployment oleh freeze dokumentasi ini.
