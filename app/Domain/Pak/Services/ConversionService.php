<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\ConversionPreview;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\Exceptions\ConversionRuleException;
use App\Domain\Pak\Rules\ConversionPersistenceValues;
use App\Domain\Pak\Rules\ConversionSaveability;
use App\Models\PakCase;
use App\Models\PakConversionPeriod;
use App\Models\PakConversionRun;
use App\Models\PakRuleSet;
use App\Models\User;
use App\Support\Database\MySqlDeadlock;
use App\Support\Database\PersonLocks;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class ConversionService
{
    public const PERMISSIONS = ['pak.conversion.calculate', 'pak.conversion.view', 'pak.case.view', 'pak.case.update', 'pak.skp.view', 'pak.employee.view', 'pak.rule-set.view'];

    public function preview(User $actor, ConversionPreviewRequest $request): ConversionPreview
    {
        $connection = DB::connection();
        $this->requireTopLevel($connection);
        foreach (self::PERMISSIONS as $permission) {
            Access::authorize($actor, $permission);
        }
        $this->requireTopLevel($connection);
        $this->requireSnapshotIsolation($connection);

        // All semantic reads use the default connection and its owned top-level snapshot.
        return $connection->transaction(fn () => app(ConversionOrchestrator::class)->build($request, app(ConversionPreviewDependencies::class)));
    }

    public function saveInitial(User $actor, ConversionSaveRequest $request): PakConversionRun
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $rolledBack = false;
            try {
                return $this->saveInitialAttempt($actor, $request, $rolledBack);
            } catch (Throwable $error) {
                if (! $rolledBack || ! MySqlDeadlock::allows($error)) {
                    throw $error;
                }
                if ($attempt === 3) {
                    throw ValidationException::withMessages(['conversion' => 'CONCURRENCY_RETRY_EXHAUSTED']);
                }
            }
        }
    }

    /** The flag carries cleanup proof only; no dependency state escapes a failed attempt. */
    private function saveInitialAttempt(User $actor, ConversionSaveRequest $request, bool &$rolledBack): PakConversionRun
    {
        $connection = DB::connection();
        $this->requireTopLevel($connection);
        foreach (self::PERMISSIONS as $permission) {
            Access::authorize($actor, $permission);
        }
        if ($connection->getPdo()->inTransaction()) {
            throw new LogicException('Conversion save requires an inactive PDO transaction.');
        }
        $plan = ConversionSaveDependencies::discover($request->pak_case_id);
        $this->requireTopLevel($connection);

        $pdo = $connection->getPdo();
        if ($pdo->inTransaction()) {
            throw new LogicException('Conversion save requires an inactive PDO transaction.');
        }
        $commitStarted = false;
        try {
            $connection->beginTransaction();
            $case = PakCase::lockForUpdate()->findOrFail($request->pak_case_id);
            if ($case->status !== 'DRAFT' || $case->finalized_at !== null || $case->lock_version !== $request->expected_case_lock_version) {
                throw ValidationException::withMessages(['case' => 'Draft changed or locked. Obtain a new preview.']);
            }
            PersonLocks::acquire([$case->person_id]);
            $rule = $case->pak_rule_set_id === null ? null : PakRuleSet::sharedLock()->find($case->pak_rule_set_id);
            if ($rule === null) {
                throw new ConversionRuleException('RULE_SET_NOT_FOUND');
            }
            if ($rule->status !== 'DRAFT') {
                throw new ConversionRuleException('RULE_SET_NOT_USABLE');
            }
            if ($rule->first_used_at === null) {
                throw new ConversionRuleException('RULE_INTEGRITY_ERROR');
            }
            $reader = ConversionSaveDependencies::coordinate($case, $rule, $plan);
            $result = app(ConversionOrchestrator::class)->build(new ConversionPreviewRequest($case->id, $request->requested_start, $request->requested_end), $reader);
            $status = app(ConversionSaveability::class)->statusFor($result);
            if (! hash_equals($result->dependency_fingerprint, $request->expected_dependency_fingerprint)
                || ! hash_equals($result->snapshot_fingerprint, $request->expected_snapshot_fingerprint)) {
                throw ValidationException::withMessages(['snapshot' => 'Dependencies or calculation changed. Obtain a new preview.']);
            }
            $nextVersion = $case->lock_version + 1;
            $run = PakConversionRun::create([
                'pak_case_id' => $case->id, 'person_id' => $case->person_id, 'pak_rule_set_id' => $rule->id,
                'revision_number' => 1, 'predecessor_run_id' => null, 'revision_reason' => null,
                'status' => $status, 'active_case_id' => $case->id,
                'requested_start' => $request->requested_start, 'requested_end' => $request->requested_end,
                'case_lock_version' => $nextVersion, 'dependency_fingerprint' => $result->dependency_fingerprint,
                'snapshot_fingerprint' => $result->snapshot_fingerprint,
                'snapshot_schema_version' => ConversionPersistenceValues::SNAPSHOT_SCHEMA_VERSION,
                'calculation_contract_version' => ConversionPersistenceValues::CALCULATION_CONTRACT_VERSION,
                'rounding_profile' => ConversionRoundingPolicy::PROFILE, 'rounding_version' => ConversionRoundingPolicy::VERSION,
                'dependency_manifest' => $result->dependencies->groups, 'original_issues' => $result->issues,
                'calculation_trace' => ['line_count' => count($result->lines), 'coverage_complete' => $result->coverage->is_complete],
                'total_numerator' => $result->exact_total === null ? null : (string) $result->exact_total->getNumerator(),
                'total_denominator' => $result->exact_total === null ? null : (string) $result->exact_total->getDenominator(),
                'review_assessment' => null, 'review_assessed_by' => null, 'review_assessed_at' => null,
                'superseded_by_user_id' => null, 'superseded_at' => null, 'created_by' => $actor->id,
            ]);
            foreach ($result->lines as $index => $line) {
                $lastMonth = $line->months[array_key_last($line->months)];
                PakConversionPeriod::create([
                    'pak_conversion_run_id' => $run->id, 'line_number' => $index + 1,
                    'period_start' => $line->months[0].'-01', 'period_end' => (new \DateTimeImmutable($lastMonth.'-01'))->format('Y-m-t'),
                    'month_count' => count($line->months), 'performance_evaluation_id' => $line->source?->evaluation_id,
                    'source_position_history_id' => $line->source?->history_id, 'employee_position_history_id' => $line->position?->history_id,
                    'functional_level_id' => $line->position?->functional_level_id,
                    'performance_predicate_rule_id' => $line->predicate?->childRuleId, 'ak_coefficient_rule_id' => $line->coefficient?->childRuleId,
                    'predicate_factor' => $line->predicate?->predicateFactor, 'annual_coefficient' => $line->coefficient?->annualCoefficient,
                    'result_numerator' => $line->exact === null ? null : (string) $line->exact->getNumerator(),
                    'result_denominator' => $line->exact === null ? null : (string) $line->exact->getDenominator(),
                    'source_snapshot' => $line->source === null ? null : array_replace(get_object_vars($line->source), ['evaluation_type' => $line->source->evaluation_type->value]),
                    'position_snapshot' => $line->position === null ? null : get_object_vars($line->position),
                    'rule_snapshot' => $line->predicate === null && $line->coefficient === null ? null : [
                        'predicate' => $line->predicate === null ? null : get_object_vars($line->predicate),
                        'coefficient' => $line->coefficient === null ? null : get_object_vars($line->coefficient),
                    ],
                    'original_issues' => $line->issues,
                    'calculation_trace' => ['regime' => $line->regime === null ? null : get_object_vars($line->regime),
                        'source_reason' => $line->source?->selection_reason, 'position_reason' => $line->position?->selection_reason],
                ]);
            }
            $case->update(['lock_version' => $nextVersion, 'updated_by' => $actor->id]);
            app(PakAuditService::class)->record($actor, $run, 'CONVERSION_RUN_SAVED', ['after' => [
                'case_id' => $case->id, 'run_id' => $run->id, 'revision_number' => 1, 'status' => $status->value,
                'issue_codes' => array_values(array_unique(array_column($result->issues, 'issue_code'))),
            ]], $case->id);

            $commitStarted = true;
            $connection->commit();

            return $run;
        } catch (Throwable $error) {
            // commit() retains Laravel ownership if its pre-commit event/PDO call throws.
            // An inactive PDO after entering commit cannot prove the commit failed.
            try {
                $level = $connection->transactionLevel();
                $active = $pdo->inTransaction();
                if ($level > 0) {
                    $connection->rollBack(0);
                }
                if ($connection->transactionLevel() !== 0 || $pdo->inTransaction()) {
                    throw new LogicException('Conversion save transaction cleanup failed.');
                }
                $rolledBack = $level > 0 && (! $commitStarted || $active);
            } catch (Throwable $cleanupError) {
                throw new LogicException('Conversion save transaction cleanup failed; retry is unsafe.', 0, $cleanupError);
            }

            throw $error;
        }
    }

    private function requireTopLevel(Connection $connection): void
    {
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Conversion operation requires ownership of a top-level transaction.');
        }
    }

    private function requireSnapshotIsolation(Connection $connection): void
    {
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new LogicException('Unsupported conversion preview snapshot connection.');
        }
        // getPdo() is the WRITE PDO used by beginTransaction, never a read replica.
        $pdo = $connection->getPdo();
        try {
            $isolation = $pdo->query('SELECT @@session.transaction_isolation')->fetchColumn();
        } catch (\PDOException $e) {
            // MariaDB 10.5 exposes tx_isolation; fall back only for unknown variable.
            if (($e->errorInfo[1] ?? null) !== 1193) {
                throw $e;
            }
            $isolation = $pdo->query('SELECT @@session.tx_isolation')->fetchColumn();
        }
        if (! is_string($isolation) || strtoupper(str_replace('-', ' ', trim($isolation))) !== 'REPEATABLE READ') {
            throw new LogicException('Conversion preview requires a repeatable-read connection.');
        }
    }
}
