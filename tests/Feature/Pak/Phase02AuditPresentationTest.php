<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Services\ConversionAssessmentService;
use App\Domain\Pak\Services\ConversionReviewService;
use App\Domain\Pak\Services\ConversionService;
use App\Filament\Pak\Resources\PakEventResource;
use App\Filament\Pak\Resources\PakEventResource\Pages\ListEvents;
use App\Filament\Pak\Support\ActivityDetailPresenter;
use App\Models\PakEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class Phase02AuditPresentationTest extends PakTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create()->givePermissionTo(['pak.access', 'pak.audit.view']));
    }

    public static function events(): array
    {
        $run = ['case_id' => 1, 'run_id' => 2, 'revision_number' => 1, 'status' => 'PREVIEW'];
        $predicate = ['pak_rule_set_id' => 1, 'predicate_code' => 'BAIK', 'predicate_name' => '<b>Synthetic</b>', 'predicate_factor' => '1.0000'];
        $coefficient = ['pak_rule_set_id' => 1, 'functional_level_id' => 3, 'annual_coefficient' => '25.000000'];

        return [
            'evidence' => ['PAK_CASE_EVIDENCE_UPLOADED', 'PakCase', 'Dokumen pendukung ditambahkan', 'Draf PAK', ['after' => ['case_id' => 1, 'evidence_id' => 2, 'mime_type' => 'application/pdf', 'size_bytes' => 100]], 4],
            'import' => ['SKP_IMPORT_COMMITTED', 'PakSkpImportBatch', 'Impor SKP dikomit', 'Batch impor SKP', [
                'before' => ['id' => 1, 'status' => 'READY'], 'after' => ['id' => 1, 'status' => 'COMMITTED', 'summary' => ['total' => 1, 'outcomes' => array_replace(array_fill_keys(['PENDING', 'READY', 'IMPORTED', 'DUPLICATE', 'CONFLICT', 'IDENTITY_AMBIGUOUS', 'INVALID', 'FAILED'], 0), ['IMPORTED' => 1])]],
            ], 10],
            'correction' => ['PERFORMANCE_EVALUATION_CORRECTED', 'PerformanceEvaluation', 'Evaluasi kinerja dikoreksi', 'Evaluasi kinerja', ['before' => ['id' => 1, 'status' => 'CURRENT'], 'after' => ['id' => 2, 'status' => 'CURRENT', 'source_row_id' => 3], 'reason' => '<b>Synthetic</b>', 'supersedes_id' => 1], 3],
            'predicate-create' => ['PREDICATE_RULE_CREATED', 'PerformancePredicateRule', 'Aturan predikat dibuat', 'Aturan predikat', ['before' => null, 'after' => $predicate], 4],
            'predicate-update' => ['PREDICATE_RULE_UPDATED', 'PerformancePredicateRule', 'Aturan predikat diperbarui', 'Aturan predikat', ['before' => array_replace($predicate, ['predicate_factor' => '0.5000']), 'after' => $predicate], 1],
            'coefficient-create' => ['COEFFICIENT_RULE_CREATED', 'AkCoefficientRule', 'Aturan koefisien dibuat', 'Aturan koefisien', ['before' => null, 'after' => $coefficient], 3],
            'coefficient-update' => ['COEFFICIENT_RULE_UPDATED', 'AkCoefficientRule', 'Aturan koefisien diperbarui', 'Aturan koefisien', ['before' => array_replace($coefficient, ['annual_coefficient' => '20.000000']), 'after' => $coefficient], 1],
            'copy' => ['RULE_SET_COPIED', 'PakRuleSet', 'Versi aturan disalin', 'Aturan PAK', ['after' => ['source_rule_set_id' => 1, 'target_rule_set_id' => 2, 'code' => 'SYNTHETIC', 'version' => 'v2', 'status' => 'DRAFT', 'predicate_count' => 1, 'coefficient_count' => 1]], 7],
            'save' => ['CONVERSION_RUN_SAVED', 'PakConversionRun', 'Konversi disimpan', 'Run konversi', ['after' => $run + ['issue_codes' => []]], 5],
            'review' => ['CONVERSION_RUN_REVIEW_ASSESSED', 'PakConversionRun', 'Tinjauan konversi dicatat', 'Run konversi', [
                'before' => $run + ['assessment_sequence' => 0],
                'after' => array_replace($run, ['status' => 'REQUIRES_REVIEW']) + ['assessment_sequence' => 1, 'comparison' => 'UNASSESSABLE', 'changed_groups' => ['rule_set'], 'current_issue_codes' => ['RULE_SET_NOT_FOUND'], 'failure_code' => 'RULE_SET_NOT_FOUND'], 'reason' => '<b>Synthetic</b>',
            ], 6],
            'replacement' => ['CONVERSION_RUN_REPLACED', 'PakConversionRun', 'Revisi konversi dibuat', 'Run konversi', [
                'before' => ['run_id' => 1, 'revision_number' => 1, 'status' => 'PREVIEW'], 'after' => array_replace($run, ['revision_number' => 2]) + ['issue_codes' => []], 'reason' => '<b>Synthetic</b>', 'supersedes_id' => 1,
            ], 5],
        ];
    }

    private function event(string $code, string $subject, mixed $payload): PakEvent
    {
        return PakEvent::create(['actor_id' => auth()->id(), 'subject_type' => $subject, 'subject_id' => 1, 'event_code' => $code, 'occurred_at' => now(), 'payload' => $payload]);
    }

    #[DataProvider('events')]
    public function test_event_allowlists_labels_escaping_and_zero_write(string $code, string $subject, string $label, string $subjectLabel, array $payload, int $changes): void
    {
        $payload['private_path'] = 'PRIVATE-SENTINEL';
        foreach (['dependency_fingerprint', 'snapshot_fingerprint', 'dependency_manifest', 'snapshot', 'calculation_trace', 'original_filename', 'disk', 'object_key', 'checksum_sha256', 'unknown'] as $key) {
            $payload['after'][$key] = ['secret' => 'PRIVATE-SENTINEL'];
        }
        $event = $this->event($code, $subject, $payload);
        $before = DB::table('pak_events')->orderBy('id')->get()->toJson();
        foreach ([ConversionService::class, ConversionAssessmentService::class, ConversionReviewService::class] as $service) {
            $this->app->bind($service, fn () => throw new \LogicException('Read presentation must not resolve conversion services.'));
        }
        DB::enableQueryLog();
        $detail = app(ActivityDetailPresenter::class)->present($event);
        $this->assertSame($label, $detail['activity']);
        $this->assertSame($subjectLabel.' #1', $detail['object']);
        $this->assertCount($changes, $detail['changes']);
        $html = view('filament.pak.audit-detail', ['detail' => $detail])->render();
        $this->assertStringNotContainsString('<b>Synthetic</b>', $html);
        if (str_contains(json_encode($detail), '<b>Synthetic')) {
            $this->assertStringContainsString('&lt;b&gt;Synthetic&lt;/b&gt;', $html);
        }
        Livewire::test(ListEvents::class)->assertCanSeeTableRecords([$event])->mountTableAction('detail', $event)
            ->assertSee($label)->assertSee($subjectLabel)->assertDontSee('PRIVATE-SENTINEL')->assertDontSee('Lihat data teknis');
        foreach (['PRIVATE-SENTINEL', 'dependency_fingerprint', 'snapshot_fingerprint', 'dependency_manifest', 'calculation_trace', '<pre', '<details', 'Lihat data teknis'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
        $this->assertSame($before, DB::table('pak_events')->orderBy('id')->get()->toJson());
        $this->assertCount(0, collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q['query'])));
        DB::disableQueryLog();
    }

    #[DataProvider('events')]
    public function test_malformed_known_payload_fails_closed_without_repair(string $code, string $subject, string $label, string $subjectLabel, array $payload): void
    {
        $first = array_key_first($payload['after']);
        $payload['after'][$first] = ['secret' => 'PRIVATE-SENTINEL'];
        $payload['reason'] = 'PRIVATE-SENTINEL';
        $event = $this->event($code, $subject, $payload);
        $raw = $event->getRawOriginal('payload');
        $detail = app(ActivityDetailPresenter::class)->present($event);
        $this->assertSame([], $detail['changes']);
        $this->assertSame('—', $detail['reason']);
        $this->assertSame($label, $detail['activity']);
        $this->assertStringNotContainsString('PRIVATE-SENTINEL', view('filament.pak.audit-detail', ['detail' => $detail])->render());
        $this->assertSame($raw, $event->fresh()->getRawOriginal('payload'));
    }

    public static function malformedValues(): array
    {
        return [
            ['case_id', '1'], ['status', 'APPROVED'], ['comparison', 'PRIVATE-SENTINEL'],
            ['assessment_sequence', -1], ['changed_groups', ['rule_set', 'rule_set']],
            ['changed_groups', ['unknown']], ['current_issue_codes', ['key' => 'BAD']],
            ['current_issue_codes', ['BAD', 'BAD']], ['failure_code', '/private/path'],
            ['reason', ['private' => 'PRIVATE-SENTINEL']],
        ];
    }

    #[DataProvider('malformedValues')]
    public function test_malformed_types_enums_lists_and_codes_are_not_normalized(string $field, mixed $value): void
    {
        $payload = self::events()['review'][4];
        if ($field === 'reason') {
            $payload['reason'] = $value;
        } else {
            $payload['after'][$field] = $value;
        }
        $event = $this->event('CONVERSION_RUN_REVIEW_ASSESSED', 'PakConversionRun', $payload);
        $raw = $event->getRawOriginal('payload');
        $detail = app(ActivityDetailPresenter::class)->present($event);
        $this->assertSame([], $detail['changes']);
        $this->assertSame('—', $detail['reason']);
        $this->assertSame($raw, $event->fresh()->getRawOriginal('payload'));
    }

    public function test_unknown_event_has_only_safe_envelope(): void
    {
        $event = $this->event('UNKNOWN_EVENT', 'Unknown\\Internal\\Class', ['after' => ['name' => 'PRIVATE-SENTINEL'], 'reason' => 'PRIVATE-SENTINEL']);
        $detail = app(ActivityDetailPresenter::class)->present($event);
        $this->assertSame([], $detail['changes']);
        $this->assertSame('UNKNOWN_EVENT', $detail['activity']);
        $this->assertSame('Objek audit #1', $detail['object']);
        $this->assertStringNotContainsString('PRIVATE-SENTINEL', json_encode($detail));
    }

    public function test_missing_permission_and_revocation_deny_listing_and_detail(): void
    {
        $event = $this->event('UNKNOWN_EVENT', 'User', []);
        $page = Livewire::test(ListEvents::class);
        auth()->user()->revokePermissionTo('pak.audit.view');
        try {
            $page->mountTableAction('detail', $event)->assertForbidden();
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->get(PakEventResource::getUrl())->assertForbidden();
    }

    public function test_inactive_actor_denied_after_mount(): void
    {
        $page = Livewire::test(ListEvents::class);
        auth()->user()->forceFill(['is_active' => false])->save();
        $page->call('$refresh')->assertForbidden();
    }
}
