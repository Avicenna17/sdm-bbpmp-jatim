<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Services\PakAuditService;
use App\Models\PakEvent;
use App\Models\PakSkpImportBatch;
use App\Models\PerformanceEvaluation;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Pak\SkpSourceFixtures;
use Tests\TestCase;

class SkpAuditBaselineTest extends TestCase
{
    use RefreshDatabase, SkpSourceFixtures;

    public function test_existing_and_minimal_skp_subjects_have_deterministic_identity(): void
    {
        $actor = User::factory()->create();
        $evaluation = PerformanceEvaluation::create($this->evaluationData());
        $batch = PakSkpImportBatch::create($this->batchData());
        foreach ([$actor, $evaluation, $batch] as $subject) {
            $event = app(PakAuditService::class)->record($actor, $subject, 'SYNTHETIC_BASELINE', ['after' => ['id' => $subject->id]])->fresh();
            $this->assertSame(class_basename($subject), $event->subject_type);
            $this->assertSame($subject->id, $event->subject_id);
            $this->assertSame($actor->id, $event->actor_id);
            $this->assertNotNull($event->occurred_at);
        }
    }

    public function test_payload_keeps_only_existing_top_level_contract(): void
    {
        $actor = User::factory()->create();
        $evaluation = PerformanceEvaluation::create($this->evaluationData());
        $safe = ['before' => ['id' => $evaluation->id, 'status' => 'CURRENT'],
            'after' => ['id' => $evaluation->id, 'source_reference' => 'SYNTHETIC/001', 'source_row_id' => 42],
            'reason' => 'Synthetic baseline', 'supersedes_id' => null];
        $unknown = array_fill_keys(['source_reference', 'source_locator', 'raw_provenance', 'object_key', 'checksum', 'NIP', 'arbitrary'], 'PRIVATE_SENTINEL');
        $event = app(PakAuditService::class)->record($actor, $evaluation, 'SYNTHETIC_BASELINE', $safe + $unknown)->fresh();
        $this->assertSame($safe, $event->payload);
        $this->assertStringNotContainsString('PRIVATE_SENTINEL', json_encode($event->payload));
    }

    public function test_arbitrary_subject_and_unneeded_import_row_remain_rejected(): void
    {
        $actor = User::factory()->create();
        $batch = PakSkpImportBatch::create($this->batchData());
        foreach ([Person::factory()->create(), $this->pendingRow($batch)] as $subject) {
            try {
                app(PakAuditService::class)->record($actor, $subject, 'REJECTED');
                $this->fail('Unsupported audit subject.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('pak_events', 0);
    }

    public function test_events_remain_append_only(): void
    {
        $actor = User::factory()->create();
        $event = app(PakAuditService::class)->record($actor, $actor, 'ORIGINAL');
        foreach ([fn () => $event->update(['event_code' => 'TAMPER']), fn () => $event->delete()] as $operation) {
            try {
                $operation();
                $this->fail('Audit must remain immutable.');
            } catch (ValidationException) {
                $this->assertSame('ORIGINAL', $event->fresh()->event_code);
            }
        }
    }

    public function test_actor_id_is_required_by_database(): void
    {
        $this->expectException(QueryException::class);
        PakEvent::create(['subject_type' => 'User', 'subject_id' => 1, 'event_code' => 'INVALID', 'actor_id' => null, 'occurred_at' => now()]);
    }

    public function test_service_requires_a_persisted_actor(): void
    {
        $subject = User::factory()->create();
        $this->expectException(QueryException::class);
        app(PakAuditService::class)->record(new User, $subject, 'INVALID');
    }
}
