# Keputusan teknis Phase 01

Bagian Phase 01 berikut merupakan histori keputusan saat foundation diterapkan; pada saat itu tidak ada business rule PENDING yang diubah menjadi keputusan final. Pembaruan client Phase 02 dicatat terpisah di bawah.

1. Koreksi Peta Jabatan diterapkan langsung pada database-pak.md dan klarifikasi spesifikasi terkait: published version berasal dari position_datasets/import_batches, independen dari bulan reporting_periods DUK.
2. UI histori menggunakan action modal pada EmployeeResource + viewer read-only, bukan relation-manager terpisah. Service/authorization/retensi identik dengan plan; tidak ada CRUD Person global.
3. Master identity (code/category/pasangan mapping) tidak diubah lewat edit, termasuk sebelum terpakai; buat master/mapping baru. Label dan is_active tetap dapat diedit. Ini menghindari repurposing ID dan menjaga label snapshot.
4. Tanggal kontrak case/histori/rule/organisasi diterima dalam format ISO YYYY-MM-DD. whereDate digunakan untuk rentang inklusif agar hasil konsisten pada DATE MySQL dan serialisasi date Eloquent di SQLite.
5. Bukti organisasi tanpa akhir hanya dianggap point-in-time. Operator dapat memasukkan rentang nyata dari bukti, bukan tanggal perkiraan; data tanpa bukti tetap null.
6. Retensi actor menggunakan RESTRICT + deaktivasi akun. Authorization Person terisolasi pada PakEmployeeAuthorization/resource/service, tanpa global PersonPolicy.
7. Factory Person disediakan; fixture PAK menggunakan helper sintetis PakTestCase dan service domain agar test tidak melewati validasi/audit.
8. No database ENUM/CHECK daftar status. Rule DRAFT nullable validity; tujuan case memakai purpose_type, sesuai tiga koreksi final.
9. Database/schema aplikasi tidak dimigrasikan saat implementasi. Fresh/upgrade/constraint diuji di SQLite in-memory; MySQL concurrency dan UAT dicatat terpisah sebagai verifikasi penerapan.

# Keputusan client Phase 02 — 23 Oktober 2026

**Sumber keputusan:** konfirmasi langsung client yang disampaikan pengguna dalam checkpoint dokumentasi ini. APPROVED di sini adalah keputusan bisnis client; bukan klaim audit regulasi baru. Jika kemudian ditemukan konflik regulasi resmi, ikuti prioritas sumber dan laporkan sebelum implementasi.

**Scope perubahan:** hanya `plans/phase-02-skp-conversion.md`, `business-rules.md`, `test-cases.md`, dan `decisions.md`, pada branch `feature/pak-phase-02`. Tidak membuat source, migration, model, service, UI, calculator, seeder, dependency, atau test executable. Tidak commit/push.

| Keputusan | Aturan | Dampak terhadap plan sebelumnya |
|---|---|---|
| Annual tersedia menjadi sumber utama tahun terkait, termasuk subset bulan | BR-SKP-002 | Annual subset tidak lagi pending dan tidak otomatis memunculkan review issue |
| Tanpa annual gunakan triwulan terakhir relevan; Jan–Okt memakai Q3 bila Q4/annual belum tersedia | BR-SKP-009, BR-SKP-006 | Tidak membuat provisional terpisah untuk Oktober; coverage asli sumber dan applied range tetap berbeda |
| Satu bulan hanya satu sumber predikat dalam satu kalkulasi, termasuk preview | BR-SKP-004/005 | Record sumber boleh coexist, pilihan kontribusi bulan tidak overlap |
| Annual majemuk akibat perubahan jabatan memakai record terkait jabatan terbaru | BR-SKP-010 | Bukan pemilihan berdasarkan waktu upload/ID; hubungan ke histori harus dapat dibuktikan |
| Jabatan/jenjang dengan TMT terbesar yang berlaku per bulan menjadi dasar koefisien | BR-JAB-001 | Beberapa perubahan dalam bulan sama memakai TMT paling akhir; bukan satu jenjang akhir untuk seluruh rentang |
| TMT tengah/akhir bulan tetap satu bulan penuh dengan jenjang terbaru | BR-JAB-001 | Tidak ada prorata hari atau partial-month blocker hanya karena TMT; tanggal histori asli tetap |
| TMT Jabatan < 2023-01-01 = LEGACY / AK Integrasi; TMT Jabatan >= 2023-01-01 = PERFORMANCE CONVERSION / konversi predikat kinerja | BR-REGIME-001 | AK Integrasi nilai existing, tidak dihitung ulang; jangan membuat calculator aturan lama; rule/version tetap extensible |
| Personnel source tambahan dan default organisasi | BR-DATA-001 | Requirement/provenance saja, tanpa perubahan importer; default bukan bukti historis |
| Materi resmi/PPT untuk guidance Admin PAK | BR-REF-001 | Pisahkan engine Phase 02, guidance, dan rule phase berikutnya; tabel tidak otomatis calculator aktif |
| Presisi penuh, total-only ROUND_HALF_UP maksimal 3 desimal, trim trailing zeros | BR-KONV-004 | Rounding tidak lagi pending; PREVIEW_ONLY tetap batas lifecycle, bukan ketidakpastian rounding |
| Nurmaulida numeric fixture tanpa mapping JF fiktif | TC-PAK-001/037 | Hasil numerik 75 tidak membuktikan resolusi jabatan produksi |
| Koreksi client golden Nurmaulida berdasarkan dokumen PAK terbaru | TC-PAK-001 | Konversi baru 75; AK Dasar 100; akumulasi dan PAK kumulatif 175. Penetapan: AK Dasar Lama 100, AK Konversi Lama tidak ada/0, AK Konversi Baru 75; kumulatif Lama 100 + Baru 75 = 175. AK Dasar tidak dicatat kembali sebagai AK Konversi Lama dan tetap berbeda dari AK Integrasi/legacy. Akumulasi/penetapan tetap phase berikutnya; scope dan schema SKP/source 02A.1 tidak berubah. |

Default Unit Kerja: **Balai Besar Penjaminan Mutu Pendidikan Provinsi Jawa Timur**. Default Instansi: **Kementerian Pendidikan Dasar dan Menengah**. Requirement personnel: KARPEG/Nomor Seri Karpeg, TEMPAT LAHIR, TGL LAHIR, TMT PANGKAT/GOL, dan TMT JABATAN -> SK. Wajib provenance sumber dan verifikasi; tidak mengasumsikan current DUK sebagai bukti historis tanpa verifikasi.

Sumber SKP sekarang di-resolve sesuai prioritas bisnis; operator meninjau hasil tanpa override ke sumber yang lebih rendah prioritas. Rule-set selection tetap berbeda: satu versi dipilih eksplisit, tidak otomatis berdasarkan tanggal/versi terbaru. Kalkulasi tetap server-authoritative, exact rational; seluruh snapshot, correction chain CURRENT dengan unique successor, replacement chain latest run dengan unique predecessor, audit privacy dan permission baseline dipertahankan.

Semua hasil Phase 02 tetap PREVIEW/REQUIRES_REVIEW/SUPERSEDED. Tidak menambah FINAL/COMPLETED/APPROVED/PAK_ESTABLISHED, akumulasi, penetapan, KP/KJ, atau KGB. Tabel, service architecture, permission, dan checkpoint 02A–02E tetap; test specification disinkronkan melalui TC-PAK-003/004 dan TC-PAK-029–038.

Yang masih pending: BR-SKP-008 (kebijakan refresh/revision calculation/draft triwulan ketika annual tersedia kemudian), BR-PERIOD-003, BR-APPOINT-008, BR-KJ-005, BR-BAL-003 serta BR-BAL-004 yang masih proposed; dasar resmi mapping Nurmaulida, pengesahan nilai master REFERENCE, lintas versi regulasi, dan retensi evidence. Requested range batas hari parsial kemudian diselesaikan melalui OD-03 di bawah. Keputusan TMT untuk perhitungan bulanan tidak menyelesaikan trigger saldo/keputusan administratif BR-BAL-003.

## Klarifikasi final checkpoint dokumentasi

- **RESOLVED:** annual diprioritaskan termasuk subset; tanpa annual gunakan triwulan terakhir relevan (Jan–Okt memakai Q3); tidak ada provisional source terpisah; satu bulan satu sumber predikat.
- **PENDING BR-SKP-008:** annual yang datang setelah calculation/draft triwulan tidak menyebabkan auto-update calculation lama. Bukti sumber saat calculation tetap tersimpan. Kebijakan kapan/bagaimana refresh atau revision belum dikunci; kontrak teknis correction/replacement chain bukan persetujuan kebijakan bisnis ini.
- Pemilihan jabatan/jenjang serta record annual terkait menggunakan TMT terbesar yang berlaku pada bulan tersebut; jika beberapa perubahan dalam bulan sama pilih TMT paling akhir. Tengah/akhir bulan tetap bulan penuh. Jangan memakai urutan record, ID database, tanggal import atau urutan Excel; jenjang bulan berikutnya tidak diterapkan mundur.
- **CALCULATION BR-KONV-004:** simpan/hitung presisi penuh, jangan bulatkan baris, jumlahkan nilai presisi penuh, ROUND_HALF_UP hanya output/total akhir maksimum tiga desimal.
- **DISPLAY:** trim trailing zeros: 100.000 -> 100; 18.750 -> 18,75; 12.500 -> 12,5; 20.833 -> 20,833. Formatting tidak mengubah calculation.
- Konsep/permission provisional pada desain terdahulu dipertahankan sebagai referensi historis/retensi tanpa membuka alur input provisional Phase 02. Arsitektur, tabel, chain, baseline permissions dan checkpoint tidak diubah. Golden Nurmaulida tetap numeric fixture tanpa mapping JF/jenjang rekaan.

# Keputusan client Phase 02C — OD-03 FINAL RESOLVED

**Sumber:** persetujuan eksplisit client atas OD-03 setelah acceptance 02C.1 (`73b87b0`); bukan klaim verifikasi regulasi baru. Contract authoritative: [OD-03 pada Phase 02C plan](plans/phase-02c-resolution-conversion-runs.md#od-03--resolved-partial-requested-range). Tidak membuat business-rule ID baru atau mengubah approval status rule lain.

Requested range wajib valid, non-empty, start <= end, contained dalam PakCase period, mulai tanggal 1 dan berakhir hari terakhir bulan (termasuk leap year) untuk directly calculable pada aspek boundary bulan. Partial start/end mempertahankan exact original dates dan menghasilkan `PARTIAL_MONTH_REQUIRES_REVIEW`; tidak ada prorata, date rounding, auto-expand, silent dropping, partial month = zero, atau complete conversion dari full months di tengah saja.

Review tidak mengotorisasi partial calculation. Diperlukan explicit revised full-month requested range yang tetap contained atau future explicit business decision. Tidak ada implicit case-period mutation/corrected range otomatis; perubahan case period memakai lifecycle case terlebih dahulu. TMT tengah/akhir bulan dan formal source coverage berbeda dari applied months tidak memicu issue ini sendiri. OD-04 dan BR-PERIOD-003 tetap belum diselesaikan oleh OD-03. Acceptance 02C.2 mengacu TC-PAK-039; keputusan ini documentation-only, bukan implementasi resolver.

# Keputusan client Phase 02C — OD-04 FINAL RESOLVED

**Sumber:** persetujuan eksplisit client atas OD-04 setelah acceptance 02C.2 (`ce888db`); bukan klaim verifikasi regulasi baru. Contract authoritative: [OD-04 pada Phase 02C plan](plans/phase-02c-resolution-conversion-runs.md#od-04--resolved-history-endgap-dalam-bulan). Tidak membuat business-rule ID baru atau mengubah approval status BR-JAB-001/rule lain.

History canonical person harus verified dan non-superseded. Start/end inclusive, null end open-ended. Setiap requested calendar month harus memiliki authoritative coverage dari hari pertama hingga terakhir tanpa documentary gap/overlap. Successor contiguous mulai sehari setelah inclusive end; mulai pada tanggal end yang sama adalah overlap. Open-ended predecessor tidak dipotong implicit.

Continuity/integrity dibuktikan sebelum memilih largest applicable TMT untuk seluruh bulan sesuai BR-JAB-001, termasuk TMT tanggal 1/tengah/akhir bulan. Tidak ada prorata atau coefficient split. Gap menghasilkan `MISSING_POSITION_HISTORY`; overlap/equal-TMT conflict menghasilkan `AMBIGUOUS_POSITION_HISTORY`. Bulan terdampak tidak mendapat authoritative assignment; jangan memperpanjang history ended, menerapkan successor retroaktif, membuat synthetic history, mengisi zero, atau memakai ID/created_at/import/arrival order sebagai tie-break.

Issue dapat membawa affected month serta safe provenance/reference, uncovered interval untuk gap atau conflicting interval/history IDs untuk overlap. Kode ini berbeda dari partial requested range OD-03 dan source-history proof 02C.1. OD-03 dan OD-05 onward tidak berubah. Acceptance 02C.3 mengacu TC-PAK-040; resolution tetap read-only/deterministic dan tidak memerlukan schema change. Keputusan ini documentation-only, bukan implementasi PositionSegmentResolver.

# Keputusan client Phase 02C — OD-09 / OD-10 schema/auth/audit

**Sumber:** persetujuan eksplisit client setelah acceptance 02C.3 (`960532c`), dengan corrective decisions berikut. Bukan klaim verifikasi regulasi baru atau perubahan approval status business rules. Authoritative schema contract: [02C.4 pada Phase 02C plan](plans/phase-02c-resolution-conversion-runs.md#9-02c4--conversion-run-schema--persistence); authorization/audit: [OD-10](plans/phase-02c-resolution-conversion-runs.md#15-authorization-dan-audit).

- **OD-09 — RESOLVED:** tepat `pak_conversion_runs` → `pak_conversion_periods`; tidak ada assignment/segment table ketiga. Monthly assignments/proof canonical berada dalam immutable versioned dependency manifest; segments derived, periods contiguous calculation/diagnostic lines menurut compatible dependencies.
- Run `case_lock_version` menyimpan authoritative version AFTER successful save increment: locked N → save atomik → case dan run version N+1. Expected pre-save version hanya concurrency input.
- Run status hanya PREVIEW/REQUIRES_REVIEW/SUPERSEDED. Ordinary preview tidak persisted. Initial save tanpa unresolved issue → PREVIEW; dengan issue → REQUIRES_REVIEW. Active projection sama dengan case ID untuk kedua status aktif, NULL untuk SUPERSEDED; nullable unique plus mandatory consistency guard, bukan partial unique index.
- Exact numerator/denominator memakai TEXT canonical integer strings, numerator nonnegative, denominator positive, simplified, paired present/NULL; tanpa float/exponent/native-int conversion requirement. Rounded output direcompute. Complex canonical rational checks tetap service/value-object invariants bila DB CHECK mengurangi portability.
- Diagnostic `source_snapshot`, `position_snapshot`, dan **`rule_snapshot` nullable**; tidak mengisi `{}`/synthetic data sebelum resolution. Calculated line wajib mempunyai applicable snapshots/operands/results. Partial OD-03 tidak membuat fabricated periods/count; incomplete run total NULL.
- Manifest v1 groups: case_context, coverage, source_candidates, selected_sources, position_assignments, history_dependencies, rule_set, versions. Preserve Annual candidate presence/absence; no whole Eloquent serialization. Source-history proof dan monthly selected position history berbeda reference.
- Snapshot schema version dan satu calculation contract version (initial canonical application constant/value `1`) plus existing rounding profile/version; bukan lima algorithm columns. Rule-set first-use tetap PakCase lifecycle; preview tidak menandai, 02C.5 tidak silently repair historical marker.
- Unique case/revision, nullable predecessor, nullable active_case, run/line. Service menjaga ownership, sequence, projection, immutable snapshots/no delete; locking OD-05 tetap wajib.
- **OD-10 — RESOLVED for schema/auth/audit mutation boundary:** active actor + pak.access melalui existing authorization/service conventions. View run: pak.conversion.view + pak.case.view. Save/recalculate/replace dan explicit review assessment: pak.conversion.calculate, pak.conversion.view, pak.case.view, pak.case.update, pak.skp.view, pak.employee.view, pak.rule-set.view. Evidence perlu pak.skp.view dan authorized run/case/person boundary; audit memakai pak.audit.view. Tidak ada role/permission baru; mutation service authorize sendiri.
- Review fields: **review_assessment, review_assessed_by, review_assessed_at**, bukan reviewed_by/reviewed_at. Assessment bukan approval AK/finalization dan tidak menghapus original issues atau mengubah calculation snapshot. No generic status setter/free-form approval, no REQUIRES_REVIEW → PREVIEW in place. Replacement membuat successor; SUPERSEDED terminal. Annual/correction tidak auto-update/replace.
- Approved events: **CONVERSION_RUN_SAVED** (initial save only, satu event), **CONVERSION_RUN_REVIEW_ASSESSED** (explicit assessment/update), **CONVERSION_RUN_REPLACED** (subject successor, predecessor melalui supersedes_id). Hanya subject PakConversionRun; no period event, no duplicate SAVED on replacement, no event read-only. Mutation/audit atomik; whitelist before/after/reason/supersedes_id dengan bounded safe IDs/status/revision/issue summaries. No manifest/full snapshots/NIP/document bodies/private paths/SQL traces in audit.

Pada checkpoint OD-09/OD-10 ini, OD-07 tetap BUSINESS PENDING, OD-08 deferred, dan OD-11 masih OPEN DECISION; status OD-11 tersebut disupersede oleh keputusan client berikut. Approval checkpoint ini hanya documentation persistence; saat itu implementasi 02C.4 belum diotorisasi. Planned acceptance mengacu TC-PAK-041.

# Keputusan client Phase 02C — OD-11 rule validity

**Status: OD-11 — RESOLVED.** Sumber: persetujuan eksplisit client setelah decision audit baseline `7c5b6f8`, termasuk clarification precedence dan issue boundary. Bukan klaim authority/verifikasi regulasi baru; tidak mengubah status business rules lain. Contract lengkap berada pada [section 11 plan Phase 02C](plans/phase-02c-resolution-conversion-runs.md#od-11--resolved-rule-validity-dan-child-resolvability); acceptance executable dimiliki 02C.5/TC-PAK-042.

- Hanya explicit PakRuleSet pada PakCase. Tidak ada latest/date-based selection, fallback, split antarversi, silent month drop, valid-subset calculation saat global validity gagal, atau fabricated zero.
- Validity inklusif: `valid_from <= requested_start AND requested_end <= valid_until`; kedua boundary harus diketahui. **NULL berarti unknown, bukan open-ended**, termasuk ketika hanya satu boundary NULL.
- Unknown boundary atau request partly/wholly outside validity → `RULE_VALIDITY_REQUIRES_REVIEW`, seluruh request diagnostics-only, tanpa numeric calculation dan exact total NULL. Diagnostic save sebagai REQUIRES_REVIEW boleh hanya jika seluruh save gates lain sukses.
- Precedence: authoritative case/explicit rule identity → rule existence/status/integrity → requested range/containment dan OD-03 coverage → validity → child predicate/coefficient resolution → numeric calculation. Validity failure short-circuits child numeric resolution tanpa unrelated child issues.
- Full-month coverage tetap tersedia, tetapi numeric results NULL. Diagnostic periods mengikuti approved 02C.4 contract; tidak menetapkan cardinality baru. Partial OD-03 tetap tanpa fabricated canonical months/count/periods.
- Setelah validity lolos, exact predicate code dan canonical functional-level ID menentukan lookup. `PREDICATE_RULE_NOT_FOUND`/`COEFFICIENT_RULE_NOT_FOUND` membuat affected line diagnostic dan total lengkap NULL. Tidak ada alias/label/level substitution atau arbitrary tie-break.
- `MISSING_FUNCTIONAL_LEVEL` hanya ketika position resolution sukses tetapi selected authoritative position tidak mempunyai canonical level untuk coefficient lookup. Position gap/overlap tetap `MISSING_POSITION_HISTORY`/`AMBIGUOUS_POSITION_HISTORY`.
- Review-saveable codes: `RULE_VALIDITY_REQUIRES_REVIEW`, `PREDICATE_RULE_NOT_FOUND`, `COEFFICIENT_RULE_NOT_FOUND`, `MISSING_FUNCTIONAL_LEVEL`, subject to all save gates. Integrity/rejection codes: `RULE_INTEGRITY_ERROR`, `RULE_SET_NOT_USABLE`, `RULE_SET_NOT_FOUND`, `INVALID_RULE_INPUT`; safe preview diagnostics diperbolehkan bila sesuai, authoritative contradictory run tidak boleh disimpan.
- Validity reason dibatasi `UNKNOWN_BOUNDARY`/`OUTSIDE_VALIDITY`; safe context berupa requested dates, validity dates, rule-set ID/version. Issues/audit tidak memuat document bodies, NIP, private paths, SQL, traces, atau whole-model serialization.
- DRAFT unused/used tetap resolvable; first use membekukan editing, bukan ACTIVE/PUBLISHED. Child predicate/coefficient tidak mempunyai inactive state; inactive FunctionalLevel tetap historical-readable/resolvable. Used version bertanggal NULL tetap readable, tidak numerically usable: create/copy explicit new version, lengkapi validity sebelum first use, associate lewat authorized PakCase lifecycle bila tersedia/approved. Jangan unlock versi lama atau mengklaim automatic replacement API.
- Preview tetap read-only; save/recalculation revalidate dan fingerprint mismatch memerlukan preview ulang, termasuk diagnostic save. Historical snapshots/results tidak berubah, dependency changes hanya dinilai melalui existing stale/review contract; tidak ada automatic successor.

**OD-07 tetap BUSINESS PENDING dan OD-08 tetap DEFERRED.** Keputusan ini tidak mengotorisasi implementasi 02C.5, perubahan schema, permission, status, atau workflow baru.

# Keputusan client Phase 02C.5.2 — initial-only delivery dan OD-12

**Sumber:** persetujuan eksplisit client setelah audit baseline `280ae93`. Documentation clarification only; bukan authorization implementasi, perubahan schema/permission, atau authority regulasi baru.

- PakCase lifecycle tetap sole writer `first_used_at`. Preview dan conversion save tidak set/repair/backfill marker. Required marker yang missing/inconsistent pada explicit case rule set menghasilkan `RULE_INTEGRITY_ERROR`; tidak ada second lifecycle writer.
- 02C.5.2 hanya read-only preview dan initial authoritative save. ANY existing PakConversionRun pada case, termasuk hanya historical/SUPERSEDED rows, menolak initial save. Tidak ada idempotent success, replay, replacement, revision allocation, atau return-existing-run. Case coordination menserialisasi double save; active-case uniqueness tetap backstop. OD-08 bukan prerequisite initial-only API dan tetap DEFERRED untuk later lifecycle work.
- Review-assessment mutation tetap accepted contract tetapi di luar delivery/executable gate 02C.5.2; umbrella acceptance tidak mengubah batas ini.
- **OD-12 — RESOLVED:** `LEGACY_REGIME_REQUIRES_REVIEW` dipicu authoritative evidence **TMT Jabatan SK < 2023-01-01** menurut BR-REGIME-001. Resolved history/provenance dapat menjadi proof; labels bukan identity keys; jangan otomatis mengganti boundary dengan `history.tmt_start` tanpa accepted documentary equivalence.
- Affected LEGACY line review-saveable subject to all save gates: REQUIRES_REVIEW, line result NULL, complete parent total NULL. Stop current-regime child lookup; jangan emit predicate/coefficient missing karena rules sengaja tidak dievaluasi. Non-LEGACY lines boleh dihitung diagnostically bila valid, bukan complete authoritative subtotal. No zero, silent drop, automatic consume/transfer/backfill/add AK Integrasi, atau reinterpret old values sebagai new conversion output. Coverage/diagnostic persistence tetap 02C.4; no new shape/table/cardinality.
- Canonical review-saveable rule/regime codes kini: `RULE_VALIDITY_REQUIRES_REVIEW`, `LEGACY_REGIME_REQUIRES_REVIEW`, `PREDICATE_RULE_NOT_FOUND`, `COEFFICIENT_RULE_NOT_FOUND`, `MISSING_FUNCTIONAL_LEVEL`. Daftar ini memperluas checkpoint OD-11 di atas tanpa mengganti existing source/position/OD-03 issue contracts atau integrity/rejection boundaries.
- Precedence: case/rule identity/integrity → requested range/containment → OD-03 → OD-11 → source/position resolution → regime/suitability → child-rule resolution → numeric calculation.
- Preview coherent/read-only tanpa coordination locks semata-mata untuk preview, tanpa run/period/audit/case/first-use mutation. Save reconstructs server-side current graph; client calculations/dependency IDs/issues/manifest bukan authority, expected case version/fingerprint hanya preconditions. Stale graph/fingerprint reject/re-preview.
- Initial-save lock order: Case → Person → RuleSet → relevant shared masters → applicable conversion-run check → evaluations → histories. Tidak ada predecessor/replacement; jangan acquire earlier resource setelah later resource. Protocol 02C.5.1 tetap.

**OD-07 tetap BUSINESS PENDING; OD-08 tetap DEFERRED.** OD-03–OD-11, source selection, exact arithmetic/rounding, Golden 75/1, dan persistence schema tidak berubah. Authoritative detail: [section 11 Phase 02C plan](plans/phase-02c-resolution-conversion-runs.md#11-02c5--conversion-run-orchestration).

# Keputusan client Phase 02C.5.2.A — OD-13 TMT Jabatan SK Evidence Contract

**OD-13 — RESOLVED.** Sumber: keputusan eksplisit client setelah evidence audit baseline `a838dea`; documentation-only, tanpa authority regulasi baru atau perubahan approval status rule lain. Contract lengkap: [OD-13 Phase 02C plan](plans/phase-02c-resolution-conversion-runs.md#od-13--resolved-tmt-jabatan-sk-evidence-contract).

- Proof memerlukan selected authoritative EmployeePositionHistory: same authoritative person, verified (`verified_by`/`verified_at`), non-superseded, dipilih melalui OD-04, `tmt_start` tersedia, serta `sk_number` dan documentary `source_ref` tersedia/nonblank. Hanya untuk row documentary-complete ini, `employee_position_histories.tmt_start` diterima sebagai effective TMT Jabatan SK untuk regime classification only.
- `sk_date` adalah document date, bukan effective TMT dan tidak dipakai pada boundary 2023. TMT tanpa documentary requirements, `sk_date`, `source_position_tmt`, reference free text sendiri, position names/labels, dan functional-level labels bukan proof yang cukup.
- Proven TMT < 2023-01-01 → existing `LEGACY_REGIME_REQUIRES_REVIEW`; proven TMT >= 2023-01-01 → CURRENT regime boleh menuju child-rule resolution subject to other gates. Resolved position/history dengan proof tidak cukup → `REGIME_EVIDENCE_REQUIRES_REVIEW`, tidak LEGACY dan tidak CURRENT.
- Evidence issue review-saveable subject to all save gates; affected line non-numeric, exact result NULL, complete parent total NULL. Skip current-regime child lookup tanpa unrelated missing predicate/coefficient issues. Preserve canonical coverage/bounded provenance; no zero, silent drop, atau consume/transfer/backfill/add stored AK Integrasi. Existing diagnostic-period schema tetap.
- Canonical review-saveable rule/regime list kini: `RULE_VALIDITY_REQUIRES_REVIEW`, `LEGACY_REGIME_REQUIRES_REVIEW`, `REGIME_EVIDENCE_REQUIRES_REVIEW`, `PREDICATE_RULE_NOT_FOUND`, `COEFFICIENT_RULE_NOT_FOUND`, `MISSING_FUNCTIONAL_LEVEL`. Ini memperluas checkpoint terdahulu tanpa mengganti existing OD-03/source/position issues.
- Precedence: case/rule identity/integrity → requested range/containment → OD-03 → OD-11 → source/position resolution → TMT Jabatan SK evidence → regime/suitability → child-rule resolution → numeric calculation.

OD-12 LEGACY semantics tetap; OD-13 hanya menetapkan documentary proof dan unavailable-proof outcome. OD-07 tetap BUSINESS PENDING, OD-08 tetap DEFERRED. OD-03/04/09/10/11, source selection, exact arithmetic, Golden 75/1, persistence schema, dan coordination order tidak berubah. Tidak mengotorisasi implementasi preview pada documentation pass ini.
