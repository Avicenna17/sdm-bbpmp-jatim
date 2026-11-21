<?php

namespace Tests\Feature\Pak;

require_once __DIR__.'/EvidenceStorageTest.php';

use App\Domain\Pak\Services\ConversionAssessmentService;
use App\Domain\Pak\Services\ConversionReviewService;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\PakEvidenceService;
use App\Domain\Pak\Services\PakEvidenceStorage;
use App\Models\PakEvent;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class EvidenceLifecycleTest extends TestCase
{
    use EvidenceFixture;

    public function test_upload_is_immutable_case_owned_with_exact_audit_and_no_other_mutation(): void
    {
        foreach ([ConversionService::class, ConversionAssessmentService::class, ConversionReviewService::class] as $service) {
            $this->app->bind($service, fn () => throw new \LogicException('Forbidden service invocation.'));
        }
        $caseBefore = $this->case->fresh()->getRawOriginal();
        $a = app(PakEvidenceService::class)->upload($this->actor, $this->case->id, $this->pdf());
        $b = app(PakEvidenceService::class)->upload($this->actor, $this->case->id, $this->pdf());
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame($a->checksum_sha256, $b->checksum_sha256);
        $this->assertSame($caseBefore, $this->case->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pak_conversion_runs', 0);
        $this->assertDatabaseCount('pak_skp_import_batches', 0);
        $this->assertDatabaseCount('pak_skp_import_rows', 0);
        $this->assertDatabaseCount('performance_evaluations', 0);
        $events = PakEvent::where('event_code', 'PAK_CASE_EVIDENCE_UPLOADED')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['after' => ['case_id' => $this->case->id, 'evidence_id' => $a->id, 'mime_type' => 'application/pdf', 'size_bytes' => strlen($this->pdfBytes())]], $events[0]->payload);
        $this->assertSame('PakCase', $events[0]->subject_type);
        foreach (['disk', 'object_key', 'checksum_sha256', 'updated_at'] as $field) {
            $this->assertArrayNotHasKey($field, $a->toArray());
        }
        foreach ([fn () => $a->update(['original_filename' => 'changed.pdf']), fn () => $a->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail();
            } catch (ValidationException) {
                $this->assertSame('synthetic.pdf', $a->fresh()->original_filename);
            }
        }
        app(PakEvidenceStorage::class)->cleanupUnreferenced($b->only(['disk', 'object_key', 'original_filename', 'mime_type', 'size_bytes', 'checksum_sha256']));
        Storage::disk('local')->assertExists($b->object_key);
    }

    public static function permissions(): array
    {
        return array_map(fn ($p) => [$p], ['pak.access', 'pak.case.view', 'pak.case.update', 'pak.skp.view', 'pak.skp.manage']);
    }

    #[DataProvider('permissions')]
    public function test_each_upload_permission_is_required_before_storage(string $permission): void
    {
        $this->actor->revokePermissionTo($permission);
        try {
            app(PakEvidenceService::class)->upload($this->actor, $this->case->id, $this->pdf());
            $this->fail();
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('pak_case_evidences', 0);
    }

    public function test_case_and_permission_are_rechecked_after_storage(): void
    {
        $storage = \Mockery::mock(PakEvidenceStorage::class)->makePartial();
        $storage->shouldReceive('store')->once()->andReturnUsing(function ($file) {
            $metadata = (new PakEvidenceStorage)->store($file);
            $this->actor->revokePermissionTo('pak.skp.manage');

            return $metadata;
        });
        try {
            (new PakEvidenceService($storage))->upload($this->actor, $this->case->id, $this->pdf());
            $this->fail();
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('pak_case_evidences', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function failedWrites(): array
    {
        return [['pak_case_evidences'], ['pak_events']];
    }

    #[DataProvider('failedWrites')]
    public function test_db_and_audit_failure_roll_back_and_compensate(string $table): void
    {
        DB::listen(function ($event) use ($table) {
            if (str_starts_with(strtolower($event->sql), 'insert') && str_contains($event->sql, $table)) {
                throw new \RuntimeException('Injected failure');
            }
        });
        try {
            app(PakEvidenceService::class)->upload($this->actor, $this->case->id, $this->pdf());
            $this->fail();
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('pak_case_evidences', 0);
            $this->assertDatabaseCount('pak_events', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function test_uncertain_commit_is_not_success_or_automatic_retry_and_object_is_retained(): void
    {
        Event::listen(TransactionCommitted::class, fn () => throw new \RuntimeException('private commit uncertainty'));
        try {
            app(PakEvidenceService::class)->upload($this->actor, $this->case->id, $this->pdf());
            $this->fail();
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('tidak dapat dikonfirmasi', $error->getMessage());
            $this->assertStringNotContainsString('private', $error->getMessage());
        }
        $this->assertDatabaseCount('pak_case_evidences', 1);
        $this->assertDatabaseCount('pak_events', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_non_draft_and_finalized_parent_reject_upload(): void
    {
        foreach ([['status' => 'CLOSED'], ['status' => 'DRAFT', 'finalized_at' => now()]] as $attributes) {
            DB::table('pak_cases')->where('id', $this->case->id)->update($attributes);
            try {
                app(PakEvidenceService::class)->upload($this->actor, $this->case->id, $this->pdf());
                $this->fail();
            } catch (ValidationException) {
                $this->assertDatabaseCount('pak_case_evidences', 0);
            }
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_inactive_actor_is_denied_before_storage(): void
    {
        $this->actor->forceFill(['is_active' => false])->save();
        try {
            app(PakEvidenceService::class)->upload($this->actor, $this->case->id, $this->pdf());
            $this->fail();
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('pak_case_evidences', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_case_state_drift_after_storage_compensates_without_mutation(): void
    {
        $storage = \Mockery::mock(PakEvidenceStorage::class)->makePartial();
        $storage->shouldReceive('store')->once()->andReturnUsing(function ($file) {
            $metadata = (new PakEvidenceStorage)->store($file);
            DB::table('pak_cases')->where('id', $this->case->id)->update(['status' => 'CLOSED']);

            return $metadata;
        });
        try {
            (new PakEvidenceService($storage))->upload($this->actor, $this->case->id, $this->pdf());
            $this->fail();
        } catch (ValidationException) {
            $this->assertDatabaseCount('pak_case_evidences', 0);
            $this->assertDatabaseCount('pak_events', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_pre_durable_commit_failure_rolls_back_and_cleans_without_retry(): void
    {
        $attempts = 0;
        Event::listen(TransactionCommitting::class, function () use (&$attempts) {
            $attempts++;
            throw new \RuntimeException('PRIVATE-COMMIT-FAILURE');
        });
        try {
            app(PakEvidenceService::class)->upload($this->actor, $this->case->id, $this->pdf());
            $this->fail('Commit failure must not return success.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Upload tidak dapat dikonfirmasi', $error->getMessage());
            $this->assertStringNotContainsString('PRIVATE-COMMIT-FAILURE', $error->getMessage());
        }
        $this->assertSame(1, $attempts);
        $this->assertDatabaseCount('pak_case_evidences', 0);
        $this->assertDatabaseCount('pak_events', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }
}
