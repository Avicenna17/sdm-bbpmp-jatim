<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\ConversionReviewRequest;
use App\Models\PakCase;
use App\Models\PakConversionRun;
use App\Models\PakRuleSet;
use App\Models\User;
use App\Support\Database\PersonLocks;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/** OD-06 explicit lifecycle assessment; single attempt, never approval or replacement. */
final class ConversionReviewService
{
    public function assessReview(User $actor, ConversionReviewRequest $request): PakConversionRun
    {
        $connection = DB::connection();
        $this->requireTopLevel($connection);
        foreach (ConversionService::PERMISSIONS as $permission) {
            Access::authorize($actor, $permission);
        }
        $plan = ConversionReviewDependencies::discover($request->pak_case_id);
        $this->requireTopLevel($connection);
        $pdo = $connection->getPdo();
        try {
            $connection->beginTransaction();
            $case = PakCase::lockForUpdate()->find($request->pak_case_id);
            if ($case === null || $case->status !== 'DRAFT' || $case->finalized_at !== null
                || $case->lock_version !== $request->expected_case_lock_version) {
                $this->reject('case');
            }
            PersonLocks::acquire([$case->person_id]);
            // Invalid/unusable current rules are assessment outcomes, not an early saveability gate.
            $rule = $case->pak_rule_set_id === null ? null : PakRuleSet::sharedLock()->find($case->pak_rule_set_id);
            $reader = ConversionReviewDependencies::coordinate($case, $rule, $plan, $request);
            $run = $reader->target();
            $assessment = app(ConversionAssessmentBuilder::class)->build($case, $run, $reader);
            if ($run->status->value !== $request->expected_run_status) {
                $this->reject('expected_run_status');
            }
            if ($assessment->assessment_sequence !== $request->expected_assessment_sequence) {
                $this->reject('expected_assessment_sequence');
            }
            if (! hash_equals($assessment->assessment_fingerprint, $request->expected_assessment_fingerprint)) {
                $this->reject('expected_assessment_fingerprint');
            }
            if ($run->status->value === 'PREVIEW' && $assessment->comparison === 'UNCHANGED') {
                throw ValidationException::withMessages(['review' => 'An unchanged preview cannot be marked for review.']);
            }
            if ($assessment->assessment_sequence === PHP_INT_MAX) {
                $this->reject('expected_assessment_sequence');
            }
            $sequence = $assessment->assessment_sequence + 1;
            $metadata = ['schema_version' => 1, 'assessment_sequence' => $sequence,
                'comparison' => $assessment->comparison, 'assessment_fingerprint' => $assessment->assessment_fingerprint,
                'current_dependency_fingerprint' => $assessment->current_dependency_fingerprint,
                'current_snapshot_fingerprint' => $assessment->current_snapshot_fingerprint,
                'changed_groups' => $assessment->changed_groups, 'current_issue_codes' => $assessment->current_issue_codes,
                'failure_code' => $assessment->failure_code, 'reason' => $request->reason];
            $before = ['case_id' => $case->id, 'run_id' => $run->id, 'revision_number' => $run->revision_number,
                'status' => $run->status->value, 'assessment_sequence' => $assessment->assessment_sequence];
            if ($run->review_assessment !== null) {
                $before['comparison'] = $run->review_assessment['comparison'];
            }
            $now = now();
            // Case/run locks serialize the review-local sequence; the raw JSON predicate also
            // fails closed if the exact observed lifecycle state is no longer present.
            $affected = DB::table('pak_conversion_runs')->where('id', $run->id)->where('pak_case_id', $case->id)
                ->where('active_case_id', $case->id)->where('status', $request->expected_run_status)
                ->whereNull('superseded_at')->whereNull('superseded_by_user_id')
                ->where('review_assessment', $run->getRawOriginal('review_assessment'))
                ->update(['status' => 'REQUIRES_REVIEW', 'review_assessment' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'review_assessed_by' => $actor->id, 'review_assessed_at' => $now, 'updated_at' => $now]);
            if ($affected !== 1) {
                $this->reject('run');
            }
            $run->refresh();
            $after = ['case_id' => $case->id, 'run_id' => $run->id, 'revision_number' => $run->revision_number,
                'status' => 'REQUIRES_REVIEW', 'assessment_sequence' => $sequence, 'comparison' => $assessment->comparison,
                'changed_groups' => $assessment->changed_groups, 'current_issue_codes' => $assessment->current_issue_codes];
            if ($assessment->failure_code !== null) {
                $after['failure_code'] = $assessment->failure_code;
            }
            app(PakAuditService::class)->record($actor, $run, 'CONVERSION_RUN_REVIEW_ASSESSED',
                ['before' => $before, 'after' => $after, 'reason' => $request->reason], $case->id);
            $connection->commit();

            return $run;
        } catch (Throwable $error) {
            // Public Laravel cleanup only. Cleanup is never interpreted as retry permission,
            // especially when PDO may have committed before a framework/event failure.
            try {
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack(0);
                }
                if ($connection->transactionLevel() !== 0 || $pdo->inTransaction()) {
                    throw new LogicException('Conversion review transaction cleanup failed.');
                }
            } catch (Throwable $cleanupError) {
                throw new LogicException('Conversion review transaction cleanup failed.', 0, $cleanupError);
            }
            throw $error;
        }
    }

    private function requireTopLevel(Connection $connection): void
    {
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new LogicException('Conversion review requires ownership of a top-level transaction.');
        }
    }

    private function reject(string $field): never
    {
        throw ValidationException::withMessages([$field => 'Conversion review state changed or is invalid. Obtain a new assessment.']);
    }
}
