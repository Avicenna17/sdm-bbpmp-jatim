<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\ConversionAssessment;
use App\Domain\Pak\DTOs\ConversionAssessmentRequest;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\Exceptions\ConversionRuleException;
use App\Models\PakCase;
use App\Models\PakConversionRun;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use LogicException;

/** OD-06 read only: no lifecycle mutation, saveability gate, retry or audit writer. */
final class ConversionAssessmentService
{
    private const GROUPS = ['case_context', 'coverage', 'source_candidates', 'selected_sources',
        'position_assignments', 'history_dependencies', 'rule_set', 'versions'];

    public function assess(User $actor, ConversionAssessmentRequest $request): ConversionAssessment
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new LogicException('Conversion assessment requires ownership of a top-level snapshot.');
        }
        foreach (ConversionService::PERMISSIONS as $permission) {
            Access::authorize($actor, $permission);
        }
        $this->requireSnapshotIsolation($connection);

        // Target, case and every semantic read belong to this one snapshot, never public preview().
        return $connection->transaction(function () use ($request): ConversionAssessment {
            $run = PakConversionRun::where('pak_case_id', $request->pak_case_id)->find($request->run_id);
            $case = PakCase::find($request->pak_case_id);
            if ($run === null || $case === null) {
                throw ValidationException::withMessages(['run' => 'Invalid conversion assessment target.']);
            }
            $sequence = $this->assessmentSequence($run);
            $dependency = $snapshot = $failure = null;
            $changed = $codes = [];
            $comparison = 'UNASSESSABLE';
            try {
                $preview = app(ConversionOrchestrator::class)->build(
                    new ConversionPreviewRequest($case->id, $run->requested_start->format('Y-m-d'), $run->requested_end->format('Y-m-d')),
                    app(ConversionPreviewDependencies::class));
                $dependency = $preview->dependency_fingerprint;
                $snapshot = $preview->snapshot_fingerprint;
                $comparison = hash_equals($run->dependency_fingerprint, $dependency)
                    && hash_equals($run->snapshot_fingerprint, $snapshot) ? 'UNCHANGED' : 'CHANGED';
                foreach (self::GROUPS as $group) {
                    if (ConversionManifestBuilder::canonical($run->dependency_manifest[$group])
                        !== ConversionManifestBuilder::canonical($preview->dependencies->groups[$group])) {
                        $changed[] = $group;
                    }
                }
                $codes = array_values(array_unique(array_column($preview->issues, 'issue_code')));
                sort($codes, SORT_STRING);
            } catch (ConversionRuleException $error) {
                // Only accepted non-comparable rule outcomes; missing child diagnostics already
                // return comparable previews. Unknown future codes must escape for explicit review.
                if (! in_array($error->issue_code, ['RULE_SET_NOT_FOUND', 'RULE_SET_NOT_USABLE', 'RULE_INTEGRITY_ERROR', 'INVALID_RULE_INPUT'], true)) {
                    throw $error;
                }
                $failure = $error->issue_code;
            } catch (ValidationException $error) {
                // Exact MonthlyCoverageRequest domain outcomes only, not arbitrary validation.
                // A saved range can cease to fit the current authoritative case range.
                $accepted = ['case_start' => 'INVALID_COVERAGE_DATE', 'case_end' => 'INVALID_COVERAGE_DATE',
                    'requested_start' => 'INVALID_COVERAGE_DATE', 'requested_end' => 'INVALID_COVERAGE_DATE'];
                $known = array_map(fn ($field, $code) => [$field => [$code]], array_keys($accepted), array_values($accepted));
                $known[] = ['case_end' => ['INVALID_CASE_RANGE']];
                $known[] = ['requested_end' => ['INVALID_REQUESTED_RANGE']];
                $known[] = ['requested_range' => ['REQUESTED_RANGE_OUTSIDE_CASE']];
                if (! in_array($error->errors(), $known, true)) {
                    throw $error;
                }
                $failure = array_values($error->errors())[0][0];
            }
            $state = ['pak_case_id' => $case->id, 'run_id' => $run->id,
                'observed_run_status' => $run->status->value, 'observed_case_lock_version' => $case->lock_version,
                'assessment_sequence' => $sequence, 'comparison' => $comparison,
                'current_dependency_fingerprint' => $dependency, 'current_snapshot_fingerprint' => $snapshot,
                'changed_groups' => $changed, 'current_issue_codes' => $codes, 'failure_code' => $failure];

            return new ConversionAssessment(
                pak_case_id: $case->id, run_id: $run->id, revision_number: (int) $run->revision_number,
                observed_run_status: $state['observed_run_status'],
                is_current_active: in_array($state['observed_run_status'], ['PREVIEW', 'REQUIRES_REVIEW'], true) && (int) $run->active_case_id === $case->id,
                observed_case_lock_version: $case->lock_version, assessment_sequence: $sequence,
                comparison: $comparison, saved_dependency_fingerprint: $run->dependency_fingerprint,
                saved_snapshot_fingerprint: $run->snapshot_fingerprint, current_dependency_fingerprint: $dependency,
                current_snapshot_fingerprint: $snapshot, changed_groups: $changed, current_issue_codes: $codes,
                failure_code: $failure, assessment_fingerprint: ConversionManifestBuilder::hash($state));
        });
    }

    private function assessmentSequence(PakConversionRun $run): int
    {
        $raw = $run->getRawOriginal('review_assessment');
        if ($raw === null) {
            return 0;
        }
        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalidMetadata();
        }
        $keys = ['schema_version', 'assessment_sequence', 'comparison', 'assessment_fingerprint',
            'current_dependency_fingerprint', 'current_snapshot_fingerprint', 'changed_groups', 'current_issue_codes', 'failure_code', 'reason'];
        if (! is_array($data) || array_diff(array_keys($data), $keys) || array_diff($keys, array_keys($data))
            || $data['schema_version'] !== 1 || ! is_int($data['assessment_sequence']) || $data['assessment_sequence'] < 1
            || ! in_array($data['comparison'], ['UNCHANGED', 'CHANGED', 'UNASSESSABLE'], true)
            || ! $this->hash($data['assessment_fingerprint'])
            || ! is_string($data['reason']) || trim($data['reason']) === '' || trim($data['reason']) !== $data['reason']) {
            $this->invalidMetadata();
        }
        foreach (['current_dependency_fingerprint', 'current_snapshot_fingerprint'] as $field) {
            if (! ($data['comparison'] === 'UNASSESSABLE' && $data[$field] === null) && ! $this->hash($data[$field])) {
                $this->invalidMetadata();
            }
        }
        foreach (['changed_groups', 'current_issue_codes'] as $field) {
            if (! is_array($data[$field]) || ! array_is_list($data[$field])) {
                $this->invalidMetadata();
            }
            foreach ($data[$field] as $value) {
                if ($field === 'changed_groups' ? ! in_array($value, self::GROUPS, true) : ! $this->code($value)) {
                    $this->invalidMetadata();
                }
            }
            if (count(array_unique($data[$field])) !== count($data[$field])) {
                $this->invalidMetadata();
            }
        }
        if ($data['comparison'] === 'UNASSESSABLE' ? ! $this->code($data['failure_code']) : $data['failure_code'] !== null) {
            $this->invalidMetadata();
        }

        return $data['assessment_sequence'];
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
    }

    private function code(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Z][A-Z0-9_]*\z/', $value) === 1;
    }

    private function invalidMetadata(): never
    {
        throw ValidationException::withMessages(['review_assessment' => 'REVIEW_ASSESSMENT_INTEGRITY_ERROR']);
    }

    private function requireSnapshotIsolation(Connection $connection): void
    {
        // Same read-snapshot requirement as accepted preview, without refactoring that frozen flow.
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new LogicException('Unsupported conversion assessment snapshot connection.');
        }
        $pdo = $connection->getPdo();
        try {
            $isolation = $pdo->query('SELECT @@session.transaction_isolation')->fetchColumn();
        } catch (\PDOException $error) {
            if (($error->errorInfo[1] ?? null) !== 1193) {
                throw $error;
            }
            $isolation = $pdo->query('SELECT @@session.tx_isolation')->fetchColumn();
        }
        if (! is_string($isolation) || strtoupper(str_replace('-', ' ', trim($isolation))) !== 'REPEATABLE READ') {
            throw new LogicException('Conversion assessment requires a repeatable-read connection.');
        }
    }
}
