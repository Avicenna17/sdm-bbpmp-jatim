# AGENTS.md
## BBPMP Jatim SDM + PAK Development Rules

This repository contains the existing **BBPMP Provinsi Jawa Timur SDM application** and the new **PAK Konversi** domain.

These instructions apply to Codex/AI coding agents working in this repository.

---

## 1. Required Reading

Before modifying any PAK-related code, read:

1. `prd.md`
2. `database.md`
3. `docs/pak/prd-pak.md`
4. `docs/pak/business-rules.md`
5. `docs/pak/workflow-pak.md`
6. `docs/pak/database-pak.md`
7. `docs/pak/test-cases.md`

If a requested implementation conflicts with these documents, stop and report the conflict before coding.

---

## 2. Source-of-Truth Priority

When sources disagree, use this priority:

1. applicable official regulation;
2. official BKN technical guidance;
3. approved BBPMP Jatim business rules;
4. real BBPMP PAK documents used as test evidence;
5. official presentation/training materials;
6. e-Kinerja BKN as workflow/UX reference;
7. tutorial videos;
8. developer assumptions.

Never convert an assumption into an implemented business rule silently.

---

## 3. Existing SDM Must Remain Stable

PAK is an extension of the existing SDM application.

Rules:

- do not rewrite existing SDM modules without a requirement;
- preserve existing `/admin` behavior;
- preserve public dashboard behavior;
- preserve existing import/reporting period workflows;
- avoid changes to existing database semantics unless explicitly documented;
- add regression tests when touching shared code.

---

## 4. Database Rules

- Laravel migrations are the schema source of truth.
- Never instruct the user to create production schema manually in MySQL Workbench.
- Reuse `people` as the canonical person identity.
- Do not use `personnel_snapshots.id` as the long-term identity of PAK records.
- PAK records must reference `people.id`.
- Finalized PAK data must store the employee snapshot used at calculation time.
- Historical PAK/AK data must not be destructively overwritten.
- Rule/master changes must not mutate finalized historical calculations.

---

## 5. Terminology Rules

Do not conflate:

```text
Pangkat != Golongan Ruang
Jabatan != Jenjang JF
KP != KJ
AK Integrasi != AK Konversi
Akumulasi AK != Final cumulative PAK in every context
```

Use explicit names in code.

Good:

```text
rank_name
grade_code
position_name
functional_level_id
```

Avoid vague fields such as:

```text
golongan_jabatan
level
status_naik
```

without precise meaning.

---

## 6. Business Rules

Every important calculation/decision must map to a rule ID from:

`docs/pak/business-rules.md`

Examples:

```text
BR-KONV-001
BR-SKP-005
BR-KPKJ-001
```

When implementing or changing business logic:

- mention the relevant rule ID in code comments only when useful;
- always cover it with automated tests;
- do not invent missing rules.

Rules marked `PENDING` must not be implemented as irreversible facts.

Prefer:

- configurable behavior;
- feature flag;
- pending status;
- explicit TODO referencing the rule ID.

---

## 7. No Hard-Coded Regulatory Numbers

Do not hard-code in calculators/controllers/resources:

- predicate percentages;
- annual AK coefficients;
- KP/KJ targets;
- AK dasar;
- transfer fixed values;
- regulatory validity dates.

Load them from versioned master rules.

Hard-coded values are acceptable only inside seeders/fixtures/tests representing a documented rule set.

---

## 8. PAK Architecture

Business logic belongs under a dedicated domain layer, for example:

```text
app/Domain/Pak/
├── Calculators/
├── Services/
├── DTOs/
├── Enums/
└── Exceptions/
```

Filament Resources/Pages should:

- collect input;
- validate UI-level concerns;
- call application/domain services;
- display result.

Filament code must not become the calculation engine.

---

## 9. Suggested Service Responsibilities

Keep responsibilities narrow.

Examples:

```text
PerformancePeriodResolver
RuleResolver
ConversionCalculator
AccumulationService
DeterminationService
EligibilityService
BalanceCycleService
DocumentService
```

Do not create one giant `PakService` containing all logic.

---

## 10. Decimal / Calculation Safety

PAK calculations are high-integrity administrative calculations.

- Do not rely on uncontrolled PHP floating-point arithmetic.
- Use decimal-safe calculation or an approved arbitrary precision approach.
- Centralize rounding policy.
- Preserve raw calculation value when useful.
- Test known examples, especially Nurmaulida.

Required golden example:

```text
2/12 × 25 = 4.167 display
10/12 × 25 = 20.833 display
new conversion total = 75
```

---

## 11. SKP Rules

Support:

- Q1;
- Q2;
- Q3;
- Q4;
- annual evaluation;
- provisional/manual emergency predicate.

Never double-count overlapping months in a finalized PAK Case.

Provisional data:

- requires reason;
- requires actor;
- must remain auditable;
- must not be silently deleted when official data arrives.

---

## 12. KP/KJ Rules

`AK requirement met` does not mean `administratively promoted`.

Model separately:

```text
calculation result
eligibility result
administrative decision
effective employment change
```

If KP and KJ are both eligible:

- process KJ first;
- do not reset AK prematurely.

KJ requires competency-test workflow.

---

## 13. SK and TMT

Store:

```text
sk_date
sk_number
tmt
```

as separate concepts.

The exact event that activates the new AK balance is currently a pending business decision (`BR-BAL-003`).

Do not bury a permanent assumption in code.

---

## 14. Document Rules

V1:

- three PAK documents;
- no automatic document number required;
- no digital signature required;
- documents are printed for wet signature;
- templates must be versioned.

Do not make TTE or automatic numbering a blocking dependency.

---

## 15. Authorization

PAK uses separate permissions/panel boundaries.

At minimum, ensure:

- employee sees only own person-level data;
- Admin SDM existing does not automatically gain PAK permission;
- PAK Admin permissions are explicit;
- Super Admin behavior remains configurable.

Authorization must be enforced in policies/queries/actions, not only by hiding navigation.

---

## 16. Privacy

Do not expose personal PAK data publicly.

Avoid logging complete:

- NIP;
- address;
- phone;
- tax identifiers;
- full uploaded row payload;
- sensitive employee documents.

Store PAK documents on private storage.

---

## 17. Testing Is Mandatory

Every calculation rule requires automated tests.

Before completing a phase, run the relevant:

- unit tests;
- feature tests;
- integration tests.

Golden test:

`TC-PAK-001 — Nurmaulida`

must pass before the base Conversion/Akumulasi/PAK engine is considered valid.

Do not mark work complete if tests are failing unless the failure is clearly pre-existing and documented.

---

## 18. Phase Discipline

Work only on the requested phase.

Recommended phases:

```text
Phase 01 Foundation
Phase 02 SKP & Conversion
Phase 03 Accumulation & Determination
Phase 04 KP/KJ Workflow
Phase 05 Portal & Documents
```

When asked for Phase 01:

- do not build calculation engine unless required by foundation contracts;
- do not build future portal/documents;
- stop at Phase 01 acceptance criteria.

---

## 19. Plan Mode

For non-trivial work:

1. inspect current repository;
2. read required docs;
3. produce a plan first;
4. list existing files impacted;
5. list new files/migrations;
6. list tests;
7. identify backward compatibility risks;
8. identify unresolved business rules;
9. implement only after plan approval when the user requested Plan Mode.

Do not produce a broad speculative rewrite.

---

## 20. Migration Safety

Before destructive schema changes:

- identify production data impact;
- prefer additive migrations;
- provide backfill strategy;
- preserve rollback when reasonably possible;
- never drop historical columns/tables casually.

PAK Foundation should be additive to the existing SDM schema.

---

## 21. Auditability

Important actions should be attributable to a user and time.

Never silently change finalized calculations.

Use revision/correction workflow when a final record needs correction.

---

## 22. Coding Style

Follow the repository's existing conventions first.

General principles:

- small focused classes;
- typed PHP where consistent with project;
- enums/value objects for controlled status where appropriate;
- Form Requests/Filament validation at boundaries;
- domain validation inside domain service;
- avoid duplicated query/calculation logic;
- descriptive naming over abbreviations.

---

## 23. Do Not

Do not:

- clone e-Kinerja BKN blindly;
- scrape or depend on private BKN application behavior;
- invent a BKN integration;
- hard-code regulatory tables in controllers;
- store all AK state in `people.total_ak`;
- delete AK history after KJ;
- calculate PAK from mutable DUK snapshot only;
- assume PAK automatically changes rank/position;
- implement KGB in PAK V1;
- add automatic TTE or document numbering unless requirements change.

---

## 24. Required Completion Report

After implementing a task/phase, report:

1. files changed;
2. migrations added;
3. business rules implemented;
4. tests added;
5. tests executed and result;
6. unresolved/pending rules;
7. backward compatibility impact;
8. manual verification steps.

---

## 25. Current Pending Business Decisions

Do not silently resolve these:

- `BR-SKP-008` — correction after provisional vs official mismatch;
- `BR-PERIOD-003` — exact universal definition of three-year window;
- `BR-APPOINT-008` — Pengangkatan Pertama vs Perpindahan terminology;
- `BR-KJ-005` — reuse PAK after failed competency test;
- `BR-BAL-003` — SK vs TMT as effective trigger for new balance.

If implementation touches one of these, explicitly stop or implement a reversible/pending design.
