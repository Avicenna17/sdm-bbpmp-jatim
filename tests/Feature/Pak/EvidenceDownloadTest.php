<?php

namespace Tests\Feature\Pak;

require_once __DIR__.'/EvidenceStorageTest.php';

use App\Domain\Pak\Services\ConversionAssessmentService;
use App\Domain\Pak\Services\ConversionReviewService;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\PakEvidenceService;
use App\Models\PakCaseEvidence;
use App\Models\PakEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EvidenceDownloadTest extends TestCase
{
    use EvidenceFixture;

    private function evidence(): PakCaseEvidence
    {
        return app(PakEvidenceService::class)->upload($this->actor, $this->case->id, $this->pdf());
    }

    private function url(PakCaseEvidence $row, ?int $caseId = null): string
    {
        return route('pak.evidence.download', ['case' => $caseId ?? $this->case->id, 'evidence' => $row->id]);
    }

    public function test_download_verified_bytes_headers_read_only_and_non_draft_access(): void
    {
        $row = $this->evidence();
        DB::table('pak_cases')->where('id', $this->case->id)->update(['status' => 'CLOSED', 'finalized_at' => now()]);
        $this->actor->revokePermissionTo(['pak.case.update', 'pak.skp.manage']);
        $before = $row->fresh()->getRawOriginal();
        $events = PakEvent::count();
        foreach ([ConversionService::class, ConversionAssessmentService::class, ConversionReviewService::class] as $service) {
            $this->app->bind($service, fn () => throw new \LogicException('Forbidden service invocation.'));
        }
        DB::enableQueryLog();
        $response = $this->get($this->url($row))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($this->pdfBytes(), $response->streamedContent());
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($row->object_key, (string) $response->headers);
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->assertSame($events, PakEvent::count());
        $this->assertCount(0, collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete)\b/i', $q['query'])));
        DB::disableQueryLog();
    }

    public static function deniedPermissions(): array
    {
        return [['pak.access'], ['pak.case.view'], ['pak.skp.view']];
    }

    #[DataProvider('deniedPermissions')]
    public function test_download_permission_denial(string $permission): void
    {
        $row = $this->evidence();
        $this->actor->revokePermissionTo($permission);
        $this->get($this->url($row))->assertForbidden();
    }

    public function test_foreign_case_and_inactive_actor_fail_closed(): void
    {
        $row = $this->evidence();
        $other = $this->case->replicate();
        $other->save();
        $this->get($this->url($row, $other->id))->assertNotFound();
        $this->actor->forceFill(['is_active' => false])->save();
        $this->get($this->url($row))->assertStatus(403);
    }

    public static function corruptions(): array
    {
        return [['missing'], ['size'], ['checksum'], ['mime'], ['path'], ['disk'], ['filename']];
    }

    #[DataProvider('corruptions')]
    public function test_corruption_is_not_repaired_or_exposed(string $kind): void
    {
        $row = $this->evidence();
        if ($kind === 'missing') {
            Storage::disk('local')->delete($row->object_key);
        } else {
            $patch = match ($kind) {
                'size' => ['size_bytes' => $row->size_bytes + 1],
                'checksum' => ['checksum_sha256' => str_repeat('a', 64)],
                'mime' => ['mime_type' => 'image/png'],
                'path' => ['object_key' => '../../PRIVATE-SENTINEL'],
                'disk' => ['disk' => 'public'],
                'filename' => ['original_filename' => '../PRIVATE-SENTINEL.pdf'],
            };
            DB::table('pak_case_evidences')->where('id', $row->id)->update($patch);
        }
        $before = $row->fresh()->getRawOriginal();
        $this->get($this->url($row))->assertNotFound()->assertDontSee('PRIVATE-SENTINEL')->assertDontSee($row->checksum_sha256);
        $this->assertSame($before, $row->fresh()->getRawOriginal());
    }
}
