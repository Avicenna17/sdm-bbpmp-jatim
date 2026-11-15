<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionReplaceRequest;
use App\Domain\Pak\Services\ConversionRunLifecycle;
use App\Domain\Pak\Services\ConversionService;
use App\Models\PakCase;
use App\Models\PakConversionPeriod;
use App\Models\PakConversionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Pak\ConversionRunFixtures;
use Tests\TestCase;

class ConversionRunLifecycleTest extends TestCase
{
    use ConversionRunFixtures;

    private PakConversionRun $run;

    private User $actor;

    private ?string $denied = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
        $this->run = PakConversionRun::create($this->runData())->fresh();
        $this->actor = User::findOrFail($this->run->created_by);
        $this->actor->forceFill(['is_active' => true])->save();
        Gate::before(fn ($user, $permission) => $permission !== $this->denied);
        PakCase::whereKey($this->run->pak_case_id)->update(['lock_version' => 2]);
    }

    private function request(array $changes = []): ConversionReplaceRequest
    {
        return ConversionReplaceRequest::from(array_replace([
            'pak_case_id' => $this->run->pak_case_id, 'expected_predecessor_run_id' => $this->run->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31', 'expected_case_lock_version' => 2,
            'expected_dependency_fingerprint' => str_repeat('c', 64), 'expected_snapshot_fingerprint' => str_repeat('d', 64),
            'revision_reason' => 'Explicit synthetic correction',
        ], $changes));
    }

    private function lockedCase(): PakCase
    {
        return PakCase::lockForUpdate()->findOrFail($this->run->pak_case_id);
    }

    private function structuralRunData(): array
    {
        $attributes = $this->run->getRawOriginal();
        unset($attributes['id']);
        foreach (['dependency_manifest', 'original_issues', 'calculation_trace', 'review_assessment'] as $field) {
            $attributes[$field] = $this->run->$field;
        }

        return $attributes;
    }

    private function assertUntouched(array $before): void
    {
        $this->assertSame($before, $this->run->fresh()->getRawOriginal());
        $this->assertSame(2, $this->run->pakCase->fresh()->lock_version);
        $this->assertDatabaseCount('pak_events', 0);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_target_validation_is_read_only_and_does_not_compare_client_hashes_to_old_snapshot(): void
    {
        $before = $this->run->getRawOriginal();
        DB::transaction(function () {
            $target = app(ConversionRunLifecycle::class)->lockTarget($this->actor, $this->lockedCase(), $this->request());
            $this->assertSame($this->run->id, $target->id);
        });
        // C.6.2.B acceptance: compare BOTH server-rebuilt hashes to predecessor; exact
        // equality must reject reason-only/no-change replacement, never return a successor.
        // This target-only slice does not pretend client expectations are rebuilt hashes.
        $this->assertUntouched($before);
    }

    public static function activeStatuses(): array
    {
        return [['PREVIEW'], ['REQUIRES_REVIEW']];
    }

    #[DataProvider('activeStatuses')]
    public function test_only_five_lifecycle_fields_change_and_periods_remain_identical(string $status): void
    {
        if ($status === 'REQUIRES_REVIEW') {
            DB::table('pak_conversion_runs')->where('id', $this->run->id)->update([
                'status' => $status, 'total_numerator' => null, 'total_denominator' => null,
                'original_issues' => json_encode([['issue_code' => 'MISSING_POSITION_HISTORY']]),
            ]);
            $this->run->refresh();
        }
        $period = PakConversionPeriod::create($this->diagnosticData($this->run->id))->fresh();
        $before = $this->run->getRawOriginal();
        $periodBefore = $period->getRawOriginal();
        $this->travelTo(now()->addDay()->startOfSecond());
        try {
            DB::transaction(fn () => app(ConversionRunLifecycle::class)->supersede($this->actor, $this->lockedCase(), $this->request()));
            $after = $this->run->fresh()->getRawOriginal();
            $allowed = ['status', 'active_case_id', 'superseded_by_user_id', 'superseded_at', 'updated_at'];
            $this->assertSame(array_diff_key($before, array_flip($allowed)), array_diff_key($after, array_flip($allowed)));
            $this->assertSame('SUPERSEDED', $after['status']);
            $this->assertNull($after['active_case_id']);
            $this->assertSame($this->actor->id, $after['superseded_by_user_id']);
            $this->assertSame(now()->format('Y-m-d H:i:s'), $after['superseded_at']);
            $this->assertSame($after['superseded_at'], $after['updated_at']);
            $this->assertNotSame($before['updated_at'], $after['updated_at']);
            $this->assertSame($periodBefore, $period->fresh()->getRawOriginal());
            $this->assertDatabaseCount('pak_conversion_runs', 1);
            $this->assertDatabaseCount('pak_events', 0);
            $this->assertSame(2, $this->run->pakCase->fresh()->lock_version);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        } finally {
            $this->travelBack();
        }
    }

    public function test_failure_after_executed_lifecycle_update_restores_predecessor(): void
    {
        $before = $this->run->getRawOriginal();
        try {
            DB::transaction(function () {
                app(ConversionRunLifecycle::class)->supersede($this->actor, $this->lockedCase(), $this->request());
                $this->assertSame('SUPERSEDED', $this->run->fresh()->getRawOriginal('status'));
                $this->assertNull($this->run->fresh()->active_case_id);
                throw new RuntimeException('After lifecycle write');
            });
            $this->fail('Fault did not execute.');
        } catch (RuntimeException $error) {
            $this->assertSame('After lifecycle write', $error->getMessage());
        }
        $this->assertUntouched($before);
        $this->assertDatabaseCount('pak_conversion_runs', 1);
        $this->assertDatabaseCount('pak_conversion_periods', 0);
    }

    public static function invalidTargets(): array
    {
        return array_map(fn ($kind) => [$kind], ['missing', 'foreign', 'historical', 'inactive', 'foreign_projection',
            'superseded_marker', 'superseded_actor', 'unknown_status', 'revision_gap', 'self_reference',
            'foreign_predecessor', 'already_has_successor', 'stale_case', 'wrong_case', 'finalized', 'not_draft']);
    }

    #[DataProvider('invalidTargets')]
    public function test_invalid_target_fails_closed(string $kind): void
    {
        $changes = [];
        $mutation = match ($kind) {
            'historical' => ['status' => 'SUPERSEDED', 'active_case_id' => null],
            'inactive' => ['active_case_id' => null],
            'superseded_marker' => ['superseded_at' => now()],
            'superseded_actor' => ['superseded_by_user_id' => $this->actor->id],
            'unknown_status' => ['status' => 'INVALID'],
            'revision_gap' => ['revision_number' => 2],
            'self_reference' => ['predecessor_run_id' => $this->run->id],
            default => [],
        };
        if (in_array($kind, ['foreign', 'foreign_projection', 'foreign_predecessor'], true)) {
            $foreign = PakConversionRun::create($this->runData());
            if ($kind === 'foreign') {
                $changes['expected_predecessor_run_id'] = $foreign->id;
            } elseif ($kind === 'foreign_projection') {
                DB::table('pak_conversion_runs')->where('id', $foreign->id)->update(['active_case_id' => null]);
                $mutation = ['active_case_id' => $foreign->pak_case_id];
            } else {
                $mutation = ['predecessor_run_id' => $foreign->id, 'revision_number' => 2, 'revision_reason' => 'Invalid chain'];
            }
        }
        if ($kind === 'already_has_successor') {
            // Deliberately inconsistent structural fixture: successor exists, target still active.
            $supersededAt = now();
            PakConversionRun::create(array_replace($this->structuralRunData(), ['revision_number' => 2,
                'predecessor_run_id' => $this->run->id, 'revision_reason' => 'Synthetic', 'status' => 'SUPERSEDED', 'active_case_id' => null,
                'superseded_by_user_id' => $this->actor->id, 'superseded_at' => $supersededAt, 'updated_at' => $supersededAt]));
        }
        if ($mutation !== []) {
            DB::table('pak_conversion_runs')->where('id', $this->run->id)->update($mutation);
        }
        if ($kind === 'missing') {
            $changes['expected_predecessor_run_id'] = 999999;
        }
        if ($kind === 'stale_case') {
            $changes['expected_case_lock_version'] = 1;
        }
        if ($kind === 'wrong_case') {
            $changes['pak_case_id'] = 999999;
        }
        if ($kind === 'finalized' || $kind === 'not_draft') {
            DB::table('pak_cases')->where('id', $this->run->pak_case_id)->update($kind === 'finalized' ? ['finalized_at' => now()] : ['status' => 'LOCKED']);
        }
        $before = $this->run->fresh()->getRawOriginal();
        try {
            DB::transaction(fn () => app(ConversionRunLifecycle::class)->supersede($this->actor, $this->lockedCase(), $this->request($changes)));
            $this->fail('Invalid target accepted: '.$kind);
        } catch (ValidationException $error) {
            $field = in_array($kind, ['stale_case', 'wrong_case', 'finalized', 'not_draft']) ? 'case' : 'expected_predecessor_run_id';
            $this->assertSame([$field], array_keys($error->errors()));
        }
        $this->assertUntouched($before);
    }

    public function test_same_range_rejects_both_expansion_and_contraction_without_normalization(): void
    {
        foreach ([['requested_start' => '2023-10-01'], ['requested_start' => '2023-12-01'],
            ['requested_end' => '2026-09-30'], ['requested_end' => '2026-11-30'],
            ['requested_start' => '2023-12-01', 'requested_end' => '2026-09-30']] as $changes) {
            $before = $this->run->getRawOriginal();
            try {
                DB::transaction(fn () => app(ConversionRunLifecycle::class)->supersede($this->actor, $this->lockedCase(), $this->request($changes)));
                $this->fail('Range change accepted.');
            } catch (ValidationException $error) {
                $this->assertSame(array_keys($changes), array_keys($error->errors()));
            }
            $this->assertUntouched($before);
        }
    }

    public function test_valid_revision_chain_and_blank_successor_reason_guard(): void
    {
        $supersededAt = now();
        DB::table('pak_conversion_runs')->where('id', $this->run->id)->update(['status' => 'SUPERSEDED', 'active_case_id' => null,
            'superseded_by_user_id' => $this->actor->id, 'superseded_at' => $supersededAt, 'updated_at' => $supersededAt]);
        $attributes = $this->structuralRunData();
        $next = PakConversionRun::create(array_replace($attributes, ['revision_number' => 2, 'predecessor_run_id' => $this->run->id, 'revision_reason' => 'Synthetic correction']));
        try {
            DB::transaction(fn () => app(ConversionRunLifecycle::class)->lockTarget($this->actor, $this->lockedCase(), $this->request()));
            $this->fail('Historical target redirected to successor.');
        } catch (ValidationException $error) {
            $this->assertSame(['expected_predecessor_run_id'], array_keys($error->errors()));
        }
        DB::transaction(function () use ($next) {
            $target = app(ConversionRunLifecycle::class)->lockTarget($this->actor, $this->lockedCase(), $this->request(['expected_predecessor_run_id' => $next->id]));
            $this->assertSame($next->id, $target->id);
        });
        DB::table('pak_conversion_runs')->where('id', $next->id)->update(['revision_reason' => '   ']);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(ConversionRunLifecycle::class)->lockTarget($this->actor, $this->lockedCase(), $this->request(['expected_predecessor_run_id' => $next->id])));
    }

    public static function missingHistoricalMetadata(): array
    {
        return [['superseded_at'], ['superseded_by_user_id']];
    }

    #[DataProvider('missingHistoricalMetadata')]
    public function test_missing_historical_supersession_metadata_rejects_without_mutation(string $missing): void
    {
        $attributes = $this->structuralRunData();
        $supersededAt = now();
        DB::table('pak_conversion_runs')->where('id', $this->run->id)->update([
            'status' => 'SUPERSEDED', 'active_case_id' => null,
            'superseded_by_user_id' => $this->actor->id, 'superseded_at' => $supersededAt, 'updated_at' => $supersededAt,
        ]);
        $target = PakConversionRun::create(array_replace($attributes, ['revision_number' => 2,
            'predecessor_run_id' => $this->run->id, 'revision_reason' => 'Synthetic correction']))->fresh();
        // Isolate each missing metadata field; the other must remain present.
        DB::table('pak_conversion_runs')->where('id', $this->run->id)->update([$missing => null]);
        $historicalBefore = $this->run->fresh()->getRawOriginal();
        $targetBefore = $target->getRawOriginal();
        $other = $missing === 'superseded_at' ? 'superseded_by_user_id' : 'superseded_at';
        $this->assertNotNull($historicalBefore[$other]);
        $this->assertNull($historicalBefore[$missing]);

        try {
            DB::transaction(fn () => app(ConversionRunLifecycle::class)->supersede(
                $this->actor, $this->lockedCase(), $this->request(['expected_predecessor_run_id' => $target->id])));
            $this->fail('Corrupted historical lifecycle accepted.');
        } catch (ValidationException $error) {
            $this->assertSame(['expected_predecessor_run_id'], array_keys($error->errors()));
        }
        $this->assertSame($targetBefore, $target->fresh()->getRawOriginal());
        $this->assertUntouched($historicalBefore);
        $this->assertDatabaseCount('pak_conversion_runs', 2);
        $this->assertDatabaseCount('pak_conversion_periods', 0);
    }

    public function test_outside_or_nested_transaction_is_rejected(): void
    {
        foreach ([0, 2] as $level) {
            try {
                for ($i = 0; $i < $level; $i++) {
                    DB::beginTransaction();
                }
                app(ConversionRunLifecycle::class)->supersede($this->actor, $this->run->pakCase, $this->request());
                $this->fail('Invalid transaction accepted.');
            } catch (\LogicException $error) {
                $this->assertSame('Conversion lifecycle requires the owned root transaction.', $error->getMessage());
            } finally {
                DB::rollBack(0);
            }
        }
    }

    public static function permissions(): array
    {
        return array_map(fn ($permission) => [$permission], ['inactive', 'pak.access', ...ConversionService::PERMISSIONS]);
    }

    #[DataProvider('permissions')]
    public function test_unauthorized_lifecycle_does_not_mutate(string $permission): void
    {
        if ($permission === 'inactive') {
            $this->actor->forceFill(['is_active' => false])->save();
        } else {
            $this->denied = $permission;
        }
        $before = $this->run->getRawOriginal();
        try {
            DB::transaction(fn () => app(ConversionRunLifecycle::class)->supersede($this->actor, $this->lockedCase(), $this->request()));
            $this->fail('Unauthorized mutation accepted.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertUntouched($before);
    }

    public function test_zero_affected_rows_is_not_success_and_outer_rollback_restores_state(): void
    {
        $before = $this->run->getRawOriginal();
        $injected = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$injected) {
            if (! $injected && str_starts_with($sql, 'update "pak_conversion_runs"')) {
                $injected = true;
                DB::table('pak_conversion_runs')->where('id', $this->run->id)->update(['active_case_id' => null]);
            }
        });
        try {
            DB::transaction(fn () => app(ConversionRunLifecycle::class)->supersede($this->actor, $this->lockedCase(), $this->request()));
            $this->fail('Zero affected rows accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('expected_predecessor_run_id', $error->errors());
        }
        $this->assertTrue($injected);
        $this->assertUntouched($before);
    }

    public static function immutableChanges(): array
    {
        return array_map(fn ($change) => [$change], [['status' => 'SUPERSEDED'], ['active_case_id' => null],
            ['total_numerator' => '76'], ['dependency_manifest' => ['changed' => []]],
            ['dependency_fingerprint' => str_repeat('e', 64)], ['snapshot_fingerprint' => str_repeat('f', 64)],
            ['requested_start' => '2024-01-01'], ['requested_end' => '2026-09-30'], ['revision_number' => 2],
            ['predecessor_run_id' => 1], ['revision_reason' => 'Changed'], ['superseded_at' => '2026-01-01']]);
    }

    #[DataProvider('immutableChanges')]
    public function test_generic_run_writes_still_rejected(array $changes): void
    {
        $before = $this->run->getRawOriginal();
        try {
            $this->run->update($changes);
            $this->fail('Generic mutation accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('snapshot', $error->errors());
        }
        $this->assertUntouched($before);
    }

    public function test_period_write_still_rejected_after_authorized_supersession(): void
    {
        $period = PakConversionPeriod::create($this->diagnosticData($this->run->id));
        DB::transaction(fn () => app(ConversionRunLifecycle::class)->supersede($this->actor, $this->lockedCase(), $this->request()));
        $this->expectException(ValidationException::class);
        $period->update(['calculation_trace' => ['changed' => true]]);
    }
}
