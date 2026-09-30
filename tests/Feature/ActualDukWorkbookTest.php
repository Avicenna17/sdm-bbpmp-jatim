<?php

namespace Tests\Feature;

use App\Domain\Import\ImportService;
use App\Domain\Import\WorkbookParser;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ActualDukWorkbookTest extends TestCase
{
    use RefreshDatabase;

    public function test_september_workbook_preview_and_commit_in_isolated_database(): void
    {
        $path = getenv('SDM_DUK_WORKBOOK');
        if (! $path || ! is_file($path)) {
            $this->markTestSkipped('Set SDM_DUK_WORKBOOK to the private September 2026 workbook.');
        }
        $checksum = hash_file('sha256', $path);
        Storage::fake('local');
        $this->seed();
        $user = User::factory()->create()->assignRole('Super Admin');
        $period = ReportingPeriod::create(['period_month' => '2026-09-01']);
        $service = app(ImportService::class);
        $batch = $service->preview($user, $period, WorkbookParser::PERSONNEL,
            new UploadedFile($path, basename($path), null, null, true));
        $this->assertSame('validated', $batch->status);
        $this->assertSame(161, $batch->total_rows);
        $this->assertSame(161, $batch->valid_rows);
        $this->assertSame(0, $batch->error_rows);
        $this->assertSame(1, $batch->warning_rows);
        $this->assertSame(1, $batch->summary['primary_warning_rows']);
        $this->assertSame(0, $batch->summary['supplemental_warning_rows']);
        $this->assertSame(0, $batch->summary['supplemental_matched']);
        $this->assertSame(0, $batch->summary['info_count']);
        $service->commit($user, $batch);
        $this->assertSame(161, $period->personnel()->count());
        $this->assertSame(['PNS' => 110, 'PPNPN' => 1, 'PPPK' => 49, 'UNKNOWN' => 1], $period->personnel()->selectRaw('employment_status, COUNT(*) as total')->groupBy('employment_status')->orderBy('employment_status')->pluck('total', 'employment_status')->all());
        $nonAsn = $period->personnel()->where('source_row_no', 165)->firstOrFail();
        $this->assertNull($nonAsn->nip_at_period);
        $this->assertSame('Tenaga Kebersihan', $nonAsn->nip_note);
        $this->assertSame('Tenaga Kebersihan', $nonAsn->raw_payload[2]);
        $this->assertSame('UNKNOWN', $period->personnel()->where('source_row_no', 111)->firstOrFail()->employment_status);
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('DUK PEGAWAI');
        // Verify the complete placement mapping, not just the population count.
        foreach ($period->personnel()->get() as $snapshot) {
            $row = $snapshot->source_row_no;
            $this->assertSame(WorkbookParser::clean($sheet->getCell('H'.$row)->getValue()), $snapshot->placement_current ?? '');
            $this->assertSame(WorkbookParser::clean($sheet->getCell('I'.$row)->getValue()), $snapshot->placement_initial ?? '');
            $program = WorkbookParser::clean($sheet->getCell('J'.$row)->getValue());
            $this->assertSame($program, $snapshot->assignment_detail ?? '');
            $this->assertSame(WorkbookParser::clean($sheet->getCell('D'.$row)->getValue()), $snapshot->rank_name ?? '');
        }
        $book->disconnectWorksheets();
        $this->assertSame($checksum, hash_file('sha256', $path));
        $this->assertSame('draft', $period->fresh()->status);
    }
}
