<?php

namespace Tests\Feature;

use App\Domain\Import\ImportService;
use App\Domain\Import\WorkbookParser;
use App\Domain\ReportingPeriod\PublishPositionService;
use App\Filament\Pages\PositionData;
use App\Models\ImportBatch;
use App\Models\PositionDataset;
use App\Models\PositionRequirementSnapshot;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PositionDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_can_change_and_extend_years_and_display_every_field(): void
    {
        $this->seed();
        Storage::fake('local');
        $user = User::factory()->create()->assignRole('Super Admin');
        $period = ReportingPeriod::create(['period_month' => '2035-09-01']);
        $this->actingAs($user);
        $response = $this->get('/templates/positions?period_id='.$period->id)->assertOk();
        $this->assertStringContainsString('template_peta_jabatan.xlsx', $response->headers->get('Content-Disposition'));
        $path = tempnam(sys_get_temp_dir(), 'position');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('PETA JABATAN');
            $this->assertSame('Pensiun '.now()->year, $sheet->getCell('M1')->getValue());
            $sheet->setCellValue('M1', 'Pensiun 2045');
            $sheet->setCellValue('Y1', 'Proyeksi Kebutuhan 2050');
            $sheet->fromArray([7, 'Induk Contoh', 'Unit Contoh', 'Analis Contoh', 'Fungsional', 8, 60, 1, 4, 3, 'Kurang', 2, 0], null, 'A2', true);
            $sheet->setCellValue('Y2', 9);
            (new Xlsx($book))->save($path);
            $service = app(ImportService::class);
            $batch = $service->preview($user, $period, WorkbookParser::POSITION, new UploadedFile($path, 'template.xlsx', null, null, true));
            $this->assertSame(0, $batch->error_rows);
            $service->commit($user, $batch);
            $this->assertDatabaseHas('position_requirement_snapshots', ['source_sequence' => 7, 'retirement_age' => 60, 'retirement_5y_total' => 2]);
            $this->assertDatabaseHas('position_projection_values', ['metric_type' => 'RETIREMENT', 'projection_year' => 2045, 'value' => 0]);
            $this->assertDatabaseHas('position_projection_values', ['metric_type' => 'REQUIREMENT', 'projection_year' => 2050, 'value' => 9]);
            Livewire::test(PositionData::class)->assertSee('Pensiun 2045')->assertSee('Proyeksi Kebutuhan 2050')->assertSee('Induk Contoh')->assertCanSeeTableRecords(PositionDataset::snapshots(false)->get());
            app(PublishPositionService::class)->publish($user, $batch->id);
            $csv = $this->get('/exports/positions/csv?period_id='.$period->id)->assertOk()->streamedContent();
            $this->assertStringContainsString('Usia Pensiun', $csv);
            $this->assertStringContainsString('Total Pensiun 5 Tahun', $csv);
            $this->assertStringContainsString('Proyeksi Kebutuhan 2050', $csv);
            $sheet->setCellValue('Z1', 'Pensiun 2045');
            (new Xlsx($book))->save($path);
            $this->expectException(\RuntimeException::class);
            app(WorkbookParser::class)->parse($path, WorkbookParser::POSITION);
        } finally {
            unlink($path);
        }
    }

    public function test_chart_totals_follow_table_filters_search_and_preserve_missing_years(): void
    {
        $this->seed();
        $user = User::factory()->create()->assignRole('Super Admin');
        $period = ReportingPeriod::create(['period_month' => '2036-01-01']);
        $old = ReportingPeriod::create(['period_month' => '2035-01-01']);
        $batch = ImportBatch::create(['reporting_period_id' => $period->id, 'uploaded_by' => $user->id, 'source_type' => 'POSITION_REQUIREMENT', 'original_filename' => 'test.xlsx', 'sha256' => str_repeat('a', 64), 'path' => 'test.xlsx', 'disk' => 'local', 'status' => 'committed', 'base_revision' => 0]);
        foreach (range(1, 12) as $i) {
            $record = PositionRequirementSnapshot::create(['reporting_period_id' => $period->id, 'import_batch_id' => $batch->id, 'position_key' => hash('sha256', (string) $i), 'position_name' => 'Analis '.$i, 'position_type' => $i === 1 ? 'Struktural' : 'Fungsional', 'incumbent_count' => 3, 'requirement_count' => 2, 'vacancy_count' => -1, 'source_row_no' => $i + 1, 'raw_payload' => []]);
            $record->projections()->createMany([
                ['metric_type' => 'RETIREMENT', 'projection_year' => 2040, 'value' => 0],
                ['metric_type' => 'REQUIREMENT', 'projection_year' => 2041, 'value' => 2],
                ['metric_type' => 'REQUIREMENT', 'projection_year' => 2045, 'value' => $i === 1 ? 5 : null],
            ]);
        }
        PositionDataset::current()->update(['draft_batch_id' => $batch->id]);
        $this->actingAs($user);
        $page = Livewire::test(PositionData::class);
        $summary = $page->instance()->positionSummary();
        $this->assertCount(12, $summary['records']);
        $this->assertEquals(0, $summary['series']['RETIREMENT'][2040]['value']);
        $this->assertEquals(24, $summary['series']['REQUIREMENT'][2041]['value']);
        $this->assertEquals(5, $summary['series']['REQUIREMENT'][2045]['value']);
        $this->assertSame(1, $summary['series']['REQUIREMENT'][2045]['known']);
        $page->assertSee('total sementara')->assertSee('Pensiun per tahun')->assertSee('Proyeksi kebutuhan per tahun');
        $page->filterTable('position_type', 'Fungsional');
        $this->assertCount(11, $page->instance()->positionSummary()['records']);
        $this->assertNull($page->instance()->positionSummary()['series']['REQUIREMENT'][2045]['value']);
        $page->searchTable('Analis 12');
        $this->assertEquals(2, $page->instance()->positionSummary()['series']['REQUIREMENT'][2041]['value']);
        $page->searchTable('Tidak ditemukan');
        $this->assertEmpty($page->instance()->positionSummary()['series']['RETIREMENT']);
        $page->assertSee('Belum ada data yang sesuai');
    }

    public function test_actual_position_workbook_retains_all_24_columns(): void
    {
        $path = getenv('SDM_POSITION_WORKBOOK');
        if (! $path || ! is_file($path)) {
            $this->markTestSkipped('Set SDM_POSITION_WORKBOOK.');
        }
        $result = app(WorkbookParser::class)->parse($path, WorkbookParser::POSITION);
        $this->assertCount(38, $result['rows']);
        $this->assertEmpty($result['issues']);
        foreach ($result['rows'] as $row) {
            foreach (['source_sequence', 'parent_org', 'work_unit', 'position_name', 'position_type', 'position_class', 'retirement_age', 'incumbent_count', 'requirement_count', 'vacancy_count', 'requirement_status', 'retirement_5y_total'] as $index => $field) {
                $this->assertEquals($row['raw_payload'][$index], $row[$field]);
            }
            $this->assertCount(12, $row['projections']);
            foreach ($row['projections'] as $index => $projection) {
                $this->assertEquals($row['raw_payload'][$index + 12], $projection['value']);
            }
        }
    }
}
