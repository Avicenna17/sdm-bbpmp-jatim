# Phase 02A.2.7B disposable concurrency verification

This is a test-only harness. No production hooks, sleeps, schema changes, or retry replacements.
`SkpImportConcurrencyTest` skips without `PAK_CONCURRENCY=1`. Once opted in, invalid configuration fails rather than skipping.

Use an isolated disposable MariaDB container, never a development/shared database. The test deliberately runs `migrate:fresh` before each case. It requires loopback, database `pak_02a1_test`, a non-3306 port explicitly matched by `PAK_CONCURRENCY_PORT`, and the existing `mysql-bootstrap.php` opt-in. The account needs access to InnoDB lock metadata. Do not provide production credentials.

Example PowerShell environment for the disposable container only:

```powershell
$env:PAK_DISPOSABLE_DB='pak_02a1_test'
$env:PAK_CONCURRENCY='1'
$env:PAK_CONCURRENCY_PORT='33318'
$env:DB_PORT='33318'
$env:DB_USERNAME='root'
$env:DB_PASSWORD=''
php vendor/bin/phpunit -c phpunit.skp-mysql.xml tests/Feature/Pak/SkpImportConcurrencyTest.php --stop-on-failure --stop-on-error
```

Each worker boots Laravel independently, receives disposable connection configuration through stdin, uses its own PDO connection, and invokes the actual commit/correction services. Results contain safe IDs/status/counts and sanitized driver diagnostics, not credentials/source payloads. Independent process IDs and MariaDB connection IDs are asserted. Final parent assertions reconnect to the database.

Barriers are exclusively test model/query listeners. New import workers pause before their first evaluation insert so both have completed the missing-CURRENT lookup. Existing-row scenarios hold a real production `FOR UPDATE` lock and wait for the competing connection to appear in `INNODB_LOCK_WAITS`. Polling is 200ms to avoid stale InnoDB metadata snapshots. Worker barrier timeout is 15 seconds, session lock wait timeout 10 seconds, and process timeout 25 seconds; all workers are stopped in `finally`. Files reside in a unique private test directory removed at teardown.

## Original verification evidence

- Exact production-target image `mariadb:10.5.10` failed the existing fresh schema test before concurrency: SQLSTATE `42S22`, vendor `1054`, CHECK referring to `b.summary` during the legacy `import_batches.published_at` ALTER. No migration was patched or skipped.
- Fallback image already available: `mariadb:10.5.11`; server reports **10.5.11-MariaDB-1:10.5.11+maria~focal**. Existing fresh/rollback/upgrade test passed (1 test, 24 assertions). This is not exact production 10.5.10.
- A, identical imports: passed; one CURRENT, IMPORTED/DUPLICATE, two commit audits.
- B, different content: passed; one CURRENT, IMPORTED/CONFLICT with NULL conflict link.
- A/B produced actual `40001 / 1213` deadlocks. The unchanged commit retry path recovered and reclassified successfully.
- C, double commit: passed (isolated run: 1 test, 20 assertions); observed blocking connection, identical persisted results, one audit/evaluation.
- D, same-predecessor corrections: passed; one successor and correction audit; loser `PREDECESSOR_NOT_CURRENT`.
- E, import holds CURRENT first: passed; import links predecessor, subsequent correction creates successor.
- **E, correction holds predecessor first: fails reproducibly.** Independent connections 50/51 in the isolated run showed a real wait. Import locks the current secondary index and waits for PRIMARY; correction owns PRIMARY and waits for `pe_current_source_uq` while superseding. MariaDB rolls back correction with `40001 / 1213`. Correction has no deadlock retry. Worker labels this unhandled exception `UNEXPECTED_FAILURE` (a harness label, not a new domain issue code).
- Isolated failing test: 1 test, 12 assertions, 1 failure, 0 skips. Final state: predecessor CURRENT, successor count 0, CURRENT count 1, correction audit count 0, commit audit count 1, batch COMMITTED, open row count 0. Atomic rollback remains intact; correction did not complete.

Reproduce only the blocker:

```powershell
php vendor/bin/phpunit -c phpunit.skp-mysql.xml tests/Feature/Pak/SkpImportConcurrencyTest.php --filter 'test_import_versus_correction.*#1'
```

The original stop gate was followed by explicit approval to make correction top-level-only and add bounded deadlock retry. The original failing result above is historical evidence, not the current correction result.

## Correction retry verification and new stop gate

- `correct()` now rejects outer transactions and retries only `40001/1213`, maximum three total attempts. Original predecessor ID is retained. `createInitial()` remains composable.
- Feature tests: evaluation service plus import commit **109 tests, 726 assertions, passed**. They cover two-attempt recovery, three-attempt exhaustion, stale original predecessor, nonretryable errors, and outer transaction guard.
- MariaDB D/E rerun: **3 tests, 62 assertions, passed**, zero skips. Correction-first produced real `40001/1213` internally and recovered: predecessor SUPERSEDED, one successor CURRENT, one correction audit, one commit audit, COMMITTED batch, no open rows. Import outcome was DUPLICATE against the predecessor because it completed while correction retried. Test assertions accept allowed completion order rather than equating initial lock acquisition with winner.
- Full configured suite then exposed a separate **A identical-import retry failure**. Isolated twice: **1 test, 12 assertions, 1 failure**, zero skips each. One import succeeds; the deadlock victim retries, then gets `ValidationException` on `performance_evaluation_id`, surfaced as `PERSISTENCE_FAILED`. Real deadlock detection remains correct (`40001/1213`, commit_retryable=true).
- Safe exception diagnostics show nested Laravel `DeadlockException` propagating to transaction level zero, followed by a new transaction's model validation failure. The unchanged row model verifies the evaluation/person link with a non-locking existence query. A REPEATABLE-READ snapshot visibility mismatch after the locking CURRENT read is a suspected cause requiring separate source review; no model guard/query fix was made.
- New stop condition honored: no broad regression or additional production fix after this failure. Production changes in this corrective pass are limited to `PerformanceEvaluationService` and the shared deadlock method in `SkpCommitRetryPolicy`; exact collision matching is unchanged.

Actual current-key `1062` diagnostics have **not** been captured. A clean supplemental concurrent `createInitial()` reproducer is now in the harness, but full-suite execution stopped before reaching it. It is not evidence until executed successfully. Real unique-collision driver-shape verification, optional row creation/closure, and full acceptance remain pending. Do not describe 7B as accepted.

## Evaluation-link visibility correction and final verification

The stop-gate statements above describe historical runs. The subsequent approved visibility correction was verified on disposable `mariadb:10.5.11`, reporting **10.5.11-MariaDB-1:10.5.11+maria~focal**, under unchanged REPEATABLE-READ isolation.

- Before patching, an independent-connection reproducer established a consistent snapshot before a competitor committed an evaluation. The authoritative locking read saw that evaluation, while the model relation's ordinary `exists()` returned false and its shared locking read returned the matching evaluation. Saving DUPLICATE then failed the old model guard (1 test, 11 assertions, one validation error). This confirmed the snapshot cause rather than assuming it.
- The only production change in this pass is `PakSkpImportRow`: evaluation/person validation uses a shared current read inside an active transaction, and an ordinary read outside transactions. The person and existence invariant remains mandatory. No isolation, retry, lock ordering, schema, or import/correction algorithm change was made.
- Focused SQLite model/commit/evaluation regression: **182 tests, 916 assertions, passed**. New tests cover matching, missing, and wrong-person links and observe the query-builder shared-lock contract. SQLite is not snapshot/concurrency proof.
- Isolated MariaDB scenario A acceptance gate: **1 test, 23 assertions, passed**. Real `40001/1213` recovered; both batches COMMITTED, one CURRENT, IMPORTED/DUPLICATE with valid links, and one audit per batch.
- Full MariaDB A–E including both E orderings and the snapshot reproducer: **7 tests, 140 assertions, passed, zero skips**. A/B recovered real deadlocks; C serialized double commit; D retained one successor and rejected the stale original predecessor; correction-first E recovered a real deadlock with a valid chain and no partial audit/open rows.
- Supplemental independent-process initial-creation collision: **1 test, 21 assertions, passed**. Actual driver diagnostics were SQLSTATE `23000`, vendor `1062`, message `Duplicate entry '<redacted>' for key 'pe_current_source_uq'`. Existing policy reported commit retryable=true and correction retryable=false without modification. The losing initial-create worker's unhandled uniqueness result is expected here: this probe verifies the real driver shape, not import collision recovery.
- Relevant PAK regression (`tests/Feature/Pak`): **420 tests, 2408 assertions, 9 skipped**, no failures. Eight concurrency cases are opt-in and were run separately on MariaDB as recorded above; the remaining disposable migration case is also opt-in. Targeted Pint, PHP syntax lint, and tracked/untracked diff whitespace checks passed. The disposable container and its anonymous volume were removed after verification.
- Exact production remains MariaDB 10.5.10. Its known fresh-schema `42S22/1054` obstacle and any previous deployment-equivalent upgrade-path evidence remain separate; this run is not exact-production-version proof.
- Optional row-creation-versus-closure concurrency was not independently forced. Final review/acceptance remains with the reviewer; no commit, push, merge, or Phase 02B work was performed.
