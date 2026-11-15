<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\ConversionReplaceRequest;
use App\Models\PakCase;
use App\Models\PakConversionRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Internal transaction participant, not a standalone replacement operation. */
final class ConversionRunLifecycle
{
    /**
     * Caller owns the root transaction and already holds Case -> Person -> Rule/masters.
     * Call at the run-resource boundary, before evaluations/histories. No earlier locks here.
     * The supplied case must be the authoritative locked model, never request/discovery state.
     */
    public function lockTarget(User $actor, PakCase $lockedCase, ConversionReplaceRequest $request): PakConversionRun
    {
        $this->requireContext($actor, $lockedCase, $request);

        $runs = PakConversionRun::where('pak_case_id', $lockedCase->id)->orderBy('id')->lockForUpdate()->get();
        $target = $runs->firstWhere('id', $request->expected_predecessor_run_id);
        if ($target === null) {
            self::reject();
        }
        $previous = null;
        foreach ($runs->sortBy('revision_number')->values() as $index => $run) {
            $isTarget = $run->id === $target->id;
            $status = $run->getRawOriginal('status');
            if ((int) $run->revision_number !== $index + 1
                || ($previous === null ? $run->predecessor_run_id !== null : (int) $run->predecessor_run_id !== $previous->id)
                || ($previous !== null && (! is_string($run->revision_reason) || trim($run->revision_reason) === ''))
                || ($isTarget
                    ? (! in_array($status, ['PREVIEW', 'REQUIRES_REVIEW'], true)
                        || (int) $run->active_case_id !== $lockedCase->id
                        || $run->superseded_at !== null || $run->superseded_by_user_id !== null)
                    : ($status !== 'SUPERSEDED' || $run->active_case_id !== null
                        || $run->superseded_by_user_id === null || $run->superseded_at === null))) {
                self::reject();
            }
            $previous = $run;
        }
        if ($previous->id !== $target->id || PakConversionRun::where('predecessor_run_id', $target->id)->exists()) {
            self::reject();
        }
        $errors = [];
        foreach (['requested_start', 'requested_end'] as $field) {
            if ($target->$field->format('Y-m-d') !== $request->$field) {
                $errors[$field] = 'Replacement must preserve the predecessor requested range.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $target;
    }

    /**
     * Caller must retain the locks from lockTarget until its entire replacement commits.
     * Caller must first rebuild authoritatively and reject when BOTH rebuilt hashes equal
     * the predecessor hashes (reason-only changes do not create a revision). Request hashes
     * are not evidence of change. Successor, case increment and the single REPLACED audit
     * belong to that caller's transaction; this internal step emits no replacement event.
     */
    public function supersede(User $actor, PakCase $lockedCase, ConversionReplaceRequest $request): void
    {
        // Reentrant locks/revalidation; the caller-held Case prevents compliant run insertion.
        $target = $this->lockTarget($actor, $lockedCase, $request);
        $this->supersedeLocked($actor, $lockedCase, $request, $target);
    }

    /**
     * Internal transition of the exact model returned by lockTarget in this transaction.
     * Caller retains that lock; no run reads or lock acquisition after dependency coordination.
     */
    public function supersedeLocked(User $actor, PakCase $lockedCase, ConversionReplaceRequest $request, PakConversionRun $target): void
    {
        $this->requireContext($actor, $lockedCase, $request);
        if (! $target->exists || $target->isDirty() || $target->id !== $request->expected_predecessor_run_id
            || (int) $target->pak_case_id !== $lockedCase->id || (int) $target->active_case_id !== $lockedCase->id
            || ! in_array($target->getRawOriginal('status'), ['PREVIEW', 'REQUIRES_REVIEW'], true)
            || $target->superseded_at !== null || $target->superseded_by_user_id !== null) {
            self::reject();
        }
        $now = now();
        $affected = DB::table('pak_conversion_runs')->where('id', $target->id)
            ->where('pak_case_id', $lockedCase->id)->where('active_case_id', $lockedCase->id)
            ->whereIn('status', ['PREVIEW', 'REQUIRES_REVIEW'])->whereNull('superseded_at')
            ->whereNull('superseded_by_user_id')
            ->update(['status' => 'SUPERSEDED', 'active_case_id' => null,
                'superseded_by_user_id' => $actor->id, 'superseded_at' => $now, 'updated_at' => $now]);
        if ($affected !== 1) {
            self::reject();
        }
    }

    private function requireContext(User $actor, PakCase $lockedCase, ConversionReplaceRequest $request): void
    {
        if (DB::transactionLevel() !== 1 || ! DB::connection()->getPdo()->inTransaction()) {
            throw new LogicException('Conversion lifecycle requires the owned root transaction.');
        }
        foreach (ConversionService::PERMISSIONS as $permission) {
            Access::authorize($actor, $permission);
        }
        if (! $lockedCase->exists || $lockedCase->isDirty() || $lockedCase->id !== $request->pak_case_id
            || $lockedCase->lock_version !== $request->expected_case_lock_version
            || $lockedCase->status !== 'DRAFT' || $lockedCase->finalized_at !== null) {
            self::reject('case');
        }
    }

    private static function reject(string $field = 'expected_predecessor_run_id'): never
    {
        throw ValidationException::withMessages([$field => 'Replacement target changed or is invalid. Obtain a new preview.']);
    }
}
