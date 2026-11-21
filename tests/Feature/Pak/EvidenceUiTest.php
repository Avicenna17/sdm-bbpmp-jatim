<?php

namespace Tests\Feature\Pak;

require_once __DIR__.'/EvidenceStorageTest.php';

use App\Domain\Pak\Queries\PakEvidenceQuery;
use App\Domain\Pak\Services\PakEvidenceService;
use App\Filament\Pak\Resources\PakCaseResource\Pages\ViewCase;
use App\Models\PakCaseEvidence;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EvidenceUiTest extends TestCase
{
    use EvidenceFixture;

    public function test_explicit_upload_without_employee_or_conversion_permission_refreshes_persisted_list(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertFalse($this->actor->can('pak.employee.view'));
        $this->assertFalse($this->actor->can('pak.conversion.view'));
        $page = Livewire::test(ViewCase::class, ['record' => $this->case->id])->assertSee('Dokumen pendukung kasus')->mountAction('uploadEvidence')
            ->setActionData(['evidence' => $this->pdf('safe.pdf')]);
        $this->assertDatabaseCount('pak_case_evidences', 0);
        $page->callMountedAction()->assertHasNoActionErrors()->assertSee('safe.pdf');
        $this->assertDatabaseCount('pak_case_evidences', 1);
        $row = PakCaseEvidence::firstOrFail();
        $page->assertDontSee($row->object_key)->assertDontSee($row->checksum_sha256);
        $this->assertSame($this->case->id, $row->pak_case_id);
    }

    public function test_read_only_escaped_list_is_zero_write_and_no_storage_metadata_is_public(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $row = app(PakEvidenceService::class)->upload($this->actor, $this->case->id, $this->pdf('<b>safe.pdf'));
        $this->actor->revokePermissionTo(['pak.case.update', 'pak.skp.manage']);
        DB::enableQueryLog();
        $page = Livewire::test(ViewCase::class, ['record' => $this->case->id])->assertActionHidden('uploadEvidence');
        $page->assertDontSee($row->object_key)->assertDontSee($row->checksum_sha256);
        $this->assertStringNotContainsString('<b>safe.pdf', $page->html());
        $this->assertStringContainsString('&lt;b&gt;safe.pdf', $page->html());
        $this->assertCount(0, collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete)\b/i', $q['query'])));
        DB::disableQueryLog();
    }

    public function test_revocation_hides_metadata_and_query_rejects(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(PakEvidenceService::class)->upload($this->actor, $this->case->id, $this->pdf('secret.pdf'));
        $page = Livewire::test(ViewCase::class, ['record' => $this->case->id])->assertSee('secret.pdf');
        $this->actor->revokePermissionTo('pak.skp.view');
        $page->call('$refresh')->assertDontSee('secret.pdf')->assertActionHidden('uploadEvidence');
        $this->expectException(HttpException::class);
        app(PakEvidenceQuery::class)->forCase($this->actor, $this->case->id);
    }

    public function test_forged_storage_fields_do_not_enter_service_mapping(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewCase::class, ['record' => $this->case->id])->callAction('uploadEvidence', [
            'evidence' => $this->pdf(), 'disk' => 'public', 'object_key' => '../../forged', 'checksum_sha256' => str_repeat('a', 64),
        ])->assertHasNoActionErrors();
        $row = PakCaseEvidence::firstOrFail();
        $this->assertSame('local', $row->disk);
        $this->assertMatchesRegularExpression('/\Apak\/evidence\/[a-f0-9]{32}\.bin\z/', $row->object_key);
        $this->assertSame(hash('sha256', $this->pdfBytes()), $row->checksum_sha256);
    }

    public function test_transport_preserves_spreadsheet_types_and_limits_but_d2_is_stricter(): void
    {
        $rules = config('livewire.temporary_file_upload');
        $this->assertSame('local', $rules['disk']);
        $this->assertSame([], $rules['preview_mimes']);
        $this->assertContains('max:15360', $rules['rules']);
        $this->assertContains('mimes:xls,xlsx,pdf,jpg,jpeg,png', $rules['rules']);
        foreach (['Xls' => 'xls', 'Xlsx' => 'xlsx'] as $writer => $extension) {
            $book = new Spreadsheet;
            $book->getActiveSheet()->setCellValue('A1', 'Synthetic');
            ob_start();
            IOFactory::createWriter($book, $writer)->save('php://output');
            $file = UploadedFile::fake()->createWithContent('existing.'.$extension, ob_get_clean());
            $this->assertFalse(Validator::make(['file' => $file], ['file' => $rules['rules']])->fails());
            $book->disconnectWorksheets();
        }
        $this->assertTrue($rules['cleanup']);
        $this->assertSame('throttle:30,1', $rules['middleware']);
        $this->assertSame(5, $rules['max_upload_time']);
    }

    public static function malformedEvidence(): array
    {
        return [['mime_type', 'image/png'], ['object_key', 'PRIVATE-SENTINEL/invalid']];
    }

    #[DataProvider('malformedEvidence')]
    public function test_malformed_metadata_is_unavailable_without_leak_or_repair(string $field, string $value): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $row = app(PakEvidenceService::class)->upload($this->actor, $this->case->id, $this->pdf());
        DB::table('pak_case_evidences')->where('id', $row->id)->update([$field => $value]);
        $before = $row->fresh()->getRawOriginal();
        DB::enableQueryLog();
        Livewire::test(ViewCase::class, ['record' => $this->case->id])
            ->assertSee('Dokumen tidak tersedia')->assertDontSee('Unduh dokumen')
            ->assertDontSee($row->object_key)->assertDontSee($row->checksum_sha256)
            ->assertDontSee('PRIVATE-SENTINEL');
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->assertCount(0, collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete)\b/i', $q['query'])));
        DB::disableQueryLog();
    }
}
