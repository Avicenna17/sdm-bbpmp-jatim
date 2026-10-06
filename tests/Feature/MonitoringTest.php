<?php

namespace Tests\Feature;

use App\Domain\Import\ImportService;
use App\Domain\Import\WorkbookParser;
use App\Domain\ReportingPeriod\PublishPeriodService;
use App\Filament\Pages\ManageData;
use App\Filament\Pages\PersonnelData;
use App\Models\ImportBatch;
use App\Models\PersonnelSnapshot;
use App\Models\PositionProjectionValue;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ReportingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->period = ReportingPeriod::create(['period_month' => '2026-09-01']);
    }

    private function file(array $rows, string $source = WorkbookParser::PERSONNEL, bool $wrongSheet = false): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle($wrongSheet ? 'NEW' : ($source === WorkbookParser::PERSONNEL ? 'DUK PEGAWAI' : 'Peta Jabatan'));
        $headers = $source === WorkbookParser::PERSONNEL ? ['NAMA', 'NIP/NIP3K', 'STATUS', 'JENIS KELAMIN', 'PENDIDIKAN', 'JABATAN', 'KELAS JABATAN'] : ['NAMA JABATAN', 'JUMLAH PEMANGKU', 'JUMLAH KEBUTUHAN', 'JUMLAH KOSONG', 'Pensiun 2033', 'Proyeksi Kebutuhan 2034'];
        $sheet->fromArray($headers);
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $v) {
                $sheet->setCellValueExplicit([$c + 1, $r + 2], (string) $v, DataType::TYPE_STRING);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'sdm');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $upload = UploadedFile::fake()->createWithContent('data.xlsx', file_get_contents($path));
        unlink($path);

        return $upload;
    }

    private function import(array $rows, string $source = WorkbookParser::PERSONNEL, ?ReportingPeriod $period = null)
    {
        $service = app(ImportService::class);
        $batch = $service->preview($this->admin, $period ?? $this->period, $source, $this->file($rows, $source));
        $this->assertSame('validated', $batch->status, json_encode($batch->issues->toArray()));
        $service->commit($this->admin, $batch);

        return $batch;
    }

    public function test_snapshot_replace_preserves_history_and_removes_missing_people(): void
    {
        $this->import([['Pegawai Rahasia', '198001012005011001', 'PNS', 'L', 'DIV', 'Analis', '7'], ['Pegawai Dua', '', 'PPNPN', 'P', 'SMA', 'Petugas', '5']]);
        $october = ReportingPeriod::create(['period_month' => '2026-10-01']);
        $this->import([['Pegawai Rahasia', '198001012005011001', 'PPPK', 'L', 'D4', 'Analis Baru', '8']], period: $october);
        $this->assertSame(2, $this->period->personnel()->count());
        $revision = $this->import([['Pegawai Rahasia', '198001012005011001', 'PNS', 'L', 'D4', 'Analis Revisi', '7']]);
        $this->assertSame(1, $revision->summary['removed']);
        $this->assertSame(1, $revision->summary['changed']);
        $this->assertSame(1, $this->period->personnel()->count());
        $this->assertSame(1, $october->personnel()->count());
    }

    public function test_duplicate_nip_blocks_commit_and_preserves_existing_snapshot(): void
    {
        $this->import([['Awal', '198001012005011001', 'PNS', 'L', 'S1', 'Analis', '7']]);
        $service = app(ImportService::class);
        $batch = $service->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $this->file([['Satu', '198001012005011001', 'PNS'], ['Dua', '198001012005011001', 'PNS']]));
        $this->assertSame('failed', $batch->status);
        $this->assertTrue($batch->issues()->where('code', 'DUPLICATE_NIP')->exists());
        try {
            $service->commit($this->admin, $batch);
            $this->fail('Commit should fail');
        } catch (ValidationException) {
        }
        $this->assertSame('Awal', $this->period->personnel()->first()->name_at_period);
    }

    public function test_unknown_status_and_name_fallback_are_explicit(): void
    {
        $batch = $this->import([['Tidak Terklasifikasi', '', '', 'P', 'D IV', 'Analis', '7'], ['Pegawai Non ASN', '-', 'PPNPN', 'L', 'SLTP', 'Petugas', '5']]);
        $this->assertGreaterThan(0, $batch->warning_rows);
        $this->assertDatabaseHas('personnel_snapshots', ['employment_group' => 'UNKNOWN', 'education_level' => 'D4']);
        $this->assertDatabaseHas('personnel_snapshots', ['employment_group' => 'PPNPN', 'education_level' => 'SMP']);
    }

    public function test_same_name_different_nip_remains_two_people(): void
    {
        $this->import([['Nama Sama', '198001012005011001', 'PNS'], ['Nama Sama', '198001012005011002', 'PNS']]);
        $this->assertSame(2, $this->period->personnel()->count());
    }

    public function test_duplicate_file_is_no_op_but_old_file_can_be_restored(): void
    {
        $service = app(ImportService::class);
        $file = $this->file([['Pertama', '198001012005011001', 'PNS']]);
        $first = $service->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $file);
        $service->commit($this->admin, $first);
        $duplicate = $service->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $file);
        $this->assertSame($first->id, $duplicate->id);
        $this->import([['Kedua', '198001012005011001', 'PNS']]);
        $restore = $service->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $file);
        $this->assertNotSame($first->id, $restore->id);
        $service->commit($this->admin, $restore);
        $this->assertSame('Pertama', $this->period->personnel()->first()->name_at_period);
    }

    public function test_dynamic_projection_publish_and_public_privacy(): void
    {
        $this->import([['Nama Sangat Rahasia', '198001012005011001', 'PNS', 'P', 'S1', 'Analis', '7']]);
        $this->import([['Analis', 2, 5, 3, 1, 6]], WorkbookParser::POSITION);
        $this->assertDatabaseHas('position_projection_values', ['projection_year' => 2033, 'metric_type' => 'RETIREMENT', 'value' => 1]);
        $this->assertSame('ready', $this->period->fresh()->status);
        app(PublishPeriodService::class)->publish($this->admin, $this->period);
        $this->get('/')->assertOk()->assertSee('Komposisi ASN')->assertSee('2033')->assertDontSee('Nama Sangat Rahasia')->assertDontSee('198001012005011001')->assertDontSee('original_filename');
        $this->get('/?employment_group=PPNPN')->assertOk()->assertSee('Komposisi PPNPN');
        $this->get('/?gender=X')->assertOk()->assertSee('Tidak ada data untuk pilihan ini.');
    }

    public function test_public_redesign_uses_real_filtered_projection_data_without_fallbacks(): void
    {
        $this->import([['Private Personnel', '198001012005011001', 'PNS', 'P', 'S1', 'Analis', '7']]);
        $this->import([['Analis', 2, 5, 3, 0, 6], ['Petugas', 1, 2, 1, '', '']], WorkbookParser::POSITION);
        app(PublishPeriodService::class)->publish($this->admin, $this->period);
        $response = $this->get('/?employment_group=PPNPN&position_search=Analis')->assertOk();
        $data = $response->viewData('data');
        $this->assertSame(0, $data['total']);
        $this->assertSame(1, $data['positions']['count']);
        $this->assertSame(5, $data['positions']['requirements']);
        $this->assertSame(0, $data['positions']['projections']['RETIREMENT'][0]['value']);
        $response->assertSee('Portal Data Kepegawaian')->assertSee('2034')->assertDontSee('Private Personnel')->assertDontSee('198001012005011001')->assertDontSee('cdn.jsdelivr.net');
        $empty = $this->get('/?position_search=Petugas')->assertOk()->viewData('data');
        $this->assertNull($empty['positions']['projections']['REQUIREMENT'][0]['value']);
        $this->assertSame(0, $empty['positions']['projections']['REQUIREMENT'][0]['known']);
        $mixed = $this->get('/')->assertOk()->viewData('data');
        $this->assertSame(1, $mixed['positions']['projections']['REQUIREMENT'][0]['known']);
        $this->assertSame(2, $mixed['positions']['projections']['REQUIREMENT'][0]['expected']);
    }

    public function test_publishing_incomplete_period_is_rejected(): void
    {
        $this->import([['Satu', '198001012005011001', 'PNS']]);
        $this->expectException(ValidationException::class);
        app(PublishPeriodService::class)->publish($this->admin, $this->period);
    }

    public function test_stale_preview_is_rejected(): void
    {
        $service = app(ImportService::class);
        $batch = $service->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $this->file([['Lama', '198001012005011001', 'PNS']]));
        $this->import([['Baru', '198001012005011001', 'PNS']]);
        $this->expectException(ValidationException::class);
        $service->commit($this->admin, $batch);
    }

    public function test_wrong_sheet_is_rejected(): void
    {
        $batch = app(ImportService::class)->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $this->file([['Satu', '', 'PPNPN']], wrongSheet: true));
        $this->assertSame('failed', $batch->status);
        $this->assertSame(0, $this->period->personnel()->count());
    }

    public function test_private_routes_and_permissions(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/exports/personnel/csv?period_id='.$this->period->id)->assertRedirect('/admin/login');
        $this->get('/api/personnel')->assertNotFound();
        $viewer = User::factory()->create()->assignRole('Internal Viewer');
        $this->actingAs($viewer)->get('/admin/manage-data')->assertOk();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/roles')->assertForbidden();
        $this->get('/templates/personnel')->assertOk();
        Livewire::test(ManageData::class)->call('publish')->assertForbidden();
    }

    public function test_admin_pages_render_and_export_is_filtered(): void
    {
        $this->import([['Pegawai ASN', '198001012005011001', 'PNS', 'P'], ['Petugas', '', 'PPNPN', 'L']]);
        $this->actingAs($this->admin);
        foreach (['/admin/login', '/admin/manage-data', '/admin/personnel-data', '/admin/position-data', '/admin/export-data', '/admin/users', '/admin/users/create', '/admin/roles', '/admin/roles/create'] as $url) {
            $r = $this->get($url);
            $this->assertContains($r->status(), [200, 302], $url.' '.$r->getContent());
        }
        $this->period->update(['status' => 'published']);
        $response = $this->get('/exports/personnel/csv?period_id='.$this->period->id.'&employment_group=ASN');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Pegawai ASN', $csv);
        $this->assertStringNotContainsString('Petugas', $csv);
        $this->assertStringContainsString('2026-09', $csv);
        $xlsx = $this->get('/exports/personnel/xlsx?period_id='.$this->period->id)->assertOk()->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'export');
        try {
            file_put_contents($path, $xlsx);
            $book = IOFactory::load($path);
            $this->assertSame('198001012005011001', $book->getActiveSheet()->getCell('D4')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $book->getActiveSheet()->getCell('D4')->getDataType());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $this->get('/admin/import-history')->assertOk();
    }

    public function test_export_rejects_unpublished_empty_and_filtered_empty_data(): void
    {
        $this->actingAs($this->admin);
        $url = '/exports/personnel/csv?period_id='.$this->period->id;
        $this->get($url)->assertStatus(422);
        $this->import([['Contoh', '198001012005011001', 'PNS']]);
        $this->get($url)->assertStatus(422);
        $this->period->update(['status' => 'ready']);
        $this->get($url)->assertStatus(422);
        $this->period->update(['status' => 'published']);
        $this->get($url)->assertOk();
        $this->get($url.'&employment_group=PPNPN')->assertStatus(422);
        $this->get('/exports/positions/xlsx?period_id='.$this->period->id)->assertStatus(422);
        Livewire::test(\App\Filament\Pages\ExportData::class)
            ->set('periodId', $this->period->id)->set('filters.employment_group', 'PPNPN')
            ->call('download')->assertHasErrors('export');
    }

    public function test_draft_is_not_public_and_published_revision_requires_permission(): void
    {
        $this->import([['Satu', '198001012005011001', 'PNS']]);
        $this->get('/?period=2026-09')->assertNotFound();
        $this->import([['Analis', 1, 3, 2, 0, 4]], WorkbookParser::POSITION);
        app(PublishPeriodService::class)->publish($this->admin, $this->period);
        $operator = User::factory()->create()->assignRole('Admin SDM');
        $batch = app(ImportService::class)->preview($operator, $this->period, WorkbookParser::PERSONNEL, $this->file([['Revisi', '198001012005011001', 'PNS']]));
        try {
            app(ImportService::class)->commit($operator, $batch);
            $this->fail('Revision must be forbidden');
        } catch (AuthorizationException) {
        }
        $this->assertSame('Satu', $this->period->personnel()->first()->name_at_period);
    }

    public function test_unchanged_diff_and_projection_cascade(): void
    {
        $this->import([['Analis', 1, 3, 2, 0, 4]], WorkbookParser::POSITION);
        $batch = $this->import([['Analis', 1, 3, 2, 0, 4], ['Petugas', 2, 4, 2, 1, 4]], WorkbookParser::POSITION);
        $this->assertSame(1, $batch->summary['unchanged']);
        $this->assertSame(1, $batch->summary['new']);
        $this->assertSame(4, PositionProjectionValue::count());
    }

    public function test_empty_workbook_cannot_erase_snapshot(): void
    {
        $this->import([['Satu', '198001012005011001', 'PNS']]);
        $batch = app(ImportService::class)->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $this->file([]));
        $this->assertSame('failed', $batch->status);
        $this->assertSame(1, $this->period->personnel()->count());
    }

    public function test_failed_commit_rolls_back_deleted_snapshot(): void
    {
        $this->import([['Awal', '198001012005011001', 'PNS']]);
        $service = app(ImportService::class);
        $batch = $service->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $this->file([['Baru', '198001012005011002', 'PNS']]));
        PersonnelSnapshot::creating(function () {
            throw new \RuntimeException('Simulated database failure');
        });
        try {
            $service->commit($this->admin, $batch);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated database failure', $e->getMessage());
        } finally {
            PersonnelSnapshot::flushEventListeners();
        }
        $this->assertSame('Awal', $this->period->personnel()->first()->name_at_period);
        $this->assertSame('validated', $batch->fresh()->status);
    }

    public function test_revalidation_creates_new_audit_without_committing_or_rewriting_old_result(): void
    {
        $service = app(ImportService::class);
        $old = $service->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $this->file([['PPNPN Contoh', 'Tenaga Kebersihan', 'PPNPN']]));
        // Simulate the stored result produced by the earlier parser.
        $old->update(['status' => 'failed', 'error_rows' => 1]);
        $this->actingAs($this->admin);
        $component = Livewire::test(ManageData::class)->call('inspect', $old->id)->call('revalidate')->assertHasNoErrors();
        $new = ImportBatch::findOrFail($component->get('batchId'));
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('validated', $new->status);
        $this->assertSame(0, $new->error_rows);
        $this->assertSame('failed', $old->fresh()->status);
        $this->assertSame(0, $this->period->personnel()->count());
        $this->assertSame($this->admin->id, $new->uploaded_by);
        $component->assertSee('Simpan data')->assertDontSee('NON_NIP_TEXT_PPNPN');
    }

    public function test_save_button_uses_non_reserved_action_and_saves_visible_personnel(): void
    {
        $batch = app(ImportService::class)->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $this->file([['Contoh Pegawai', '', 'PPNPN']]));
        $this->actingAs($this->admin);
        $component = Livewire::test(ManageData::class)->call('inspect', $batch->id)
            ->assertSeeHtml('wire:click="saveImport"')->assertDontSeeHtml('wire:click="commit"')
            ->call('saveImport')->assertHasNoErrors()->assertSee('Data berhasil disimpan')->assertSee('Lihat Daftar Pegawai');
        $this->assertSame('committed', $batch->fresh()->status);
        Livewire::test(PersonnelData::class)
            ->filterTable('reporting_period_id', $this->period->id)
            ->assertCanSeeTableRecords($this->period->personnel()->get());
        $component->call('saveImport')->assertHasNoErrors();
        $this->assertSame(1, $this->period->personnel()->count());
    }

    public function test_failed_save_is_visible_and_preserves_previous_data(): void
    {
        $batch = app(ImportService::class)->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $this->file([['Lama', '', 'PPNPN']]));
        $this->import([['Terbaru', '', 'PPNPN']]);
        $this->actingAs($this->admin);
        Livewire::test(ManageData::class)->call('inspect', $batch->id)->call('saveImport')
            ->assertHasErrors('import')->assertNotified('Data belum tersimpan');
        $this->assertSame('Terbaru', $this->period->personnel()->first()->name_at_period);
        $this->assertSame('validated', $batch->fresh()->status);
    }

    public function test_duk_template_round_trip_preserves_rank_and_both_placements(): void
    {
        $this->actingAs($this->admin);
        $bytes = $this->get('/templates/personnel?period_id='.$this->period->id)->assertOk()->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'template');
        try {
            file_put_contents($path, $bytes);
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('DUK PEGAWAI');
            $this->assertSame('DAFTAR PEGAWAI BALAI BESAR PENJAMINAN MUTU PENDIDIKAN PROVINSI JAWA TIMUR', $sheet->getCell('A1')->getValue());
            $this->assertSame('Bulan - tahun', $sheet->getCell('A2')->getValue());
            $this->assertSame('SK Tim Kerja (Baru)', $sheet->getCell('H4')->getValue());
            $this->assertSame('SK Tim Kerja (Awal)', $sheet->getCell('I4')->getValue());
            $sheet->fromArray([1, 'Pegawai Contoh', 'Tenaga Kebersihan', 'Pangkat Contoh', null, 'Petugas', 5, 'Tim Baru', 'Tim Awal', 'SMA', 'L', 'PNPN'], null, 'A5');
            (new Xlsx($book))->save($path);
            $book->disconnectWorksheets();
            $batch = app(ImportService::class)->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, new UploadedFile($path, 'template.xlsx', null, null, true));
            $this->assertSame(0, $batch->error_rows);
            $this->assertSame(0, $batch->warning_rows);
            app(ImportService::class)->commit($this->admin, $batch);
            $this->assertDatabaseHas('personnel_snapshots', ['rank_name' => 'Pangkat Contoh', 'placement_current' => 'Tim Baru', 'placement_initial' => 'Tim Awal', 'nip_note' => 'Tenaga Kebersihan']);
            Livewire::test(PersonnelData::class)->assertSee('Pangkat Contoh')->assertSee('Tim Baru')->assertSee('Tim Awal')->assertSee('Tenaga Kebersihan');
        } finally {
            unlink($path);
        }
    }

    public function test_personnel_charts_follow_filters_search_and_group_without_pagination(): void
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['ASN Contoh '.$i, '19800101200501'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'PNS', 'L', 'S1', 'Analis', '7'];
        }
        $rows[] = ['Petugas Contoh', '', 'PPNPN', 'P', 'SMA', 'Petugas', '5'];
        $rows[] = ['Status Kosong', '', '', '', '', '', ''];
        $this->import($rows);
        $other = ReportingPeriod::create(['period_month' => '2026-08-01']);
        $this->import([['Periode Lain', '', 'PPNPN']], WorkbookParser::PERSONNEL, $other);
        $this->actingAs($this->admin);
        $page = Livewire::test(PersonnelData::class);
        $this->assertSame(14, $page->instance()->personnelSummary()['total']);
        $page->call('selectPersonnelGroup', 'ASN');
        $this->assertSame(12, $page->instance()->personnelSummary()['total']);
        $this->assertSame(['Laki-laki' => 12], $page->instance()->personnelSummary()['charts']['gender']['values']);
        $page->searchTable('ASN Contoh 11');
        $this->assertSame(1, $page->instance()->personnelSummary()['total']);
        $page->searchTable('')->call('selectPersonnelGroup', 'PPNPN');
        $this->assertSame(1, $page->instance()->personnelSummary()['total']);
        $page->filterTable('gender', 'L');
        $this->assertSame(0, $page->instance()->personnelSummary()['total']);
        $page->assertSee('Belum ada data yang sesuai');
        $page->removeTableFilter('gender')->call('selectPersonnelGroup', 'UNKNOWN');
        $this->assertSame(['-' => 1], $page->instance()->personnelSummary()['charts']['employment_status']['values']);
        $page->call('selectPersonnelGroup', '')->filterTable('reporting_period_id', $other->id);
        $this->assertSame(1, $page->instance()->personnelSummary()['total']);
        $page->removeTableFilter('reporting_period_id');
        $this->assertSame(15, $page->instance()->personnelSummary()['total']);
        $this->assertTrue($page->instance()->personnelSummary()['multiplePeriods']);
    }

    public function test_revalidation_checks_permission_and_original_checksum(): void
    {
        $service = app(ImportService::class);
        $old = $service->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $this->file([['PPNPN Contoh', '', 'PPNPN']]));
        $viewer = User::factory()->create()->assignRole('Internal Viewer');
        try {
            $service->revalidate($viewer, $old);
            $this->fail('Viewer must be forbidden');
        } catch (AuthorizationException) {
        }
        Storage::disk('local')->put($old->path, 'changed content');
        $this->expectException(ValidationException::class);
        $service->revalidate($this->admin, $old);
    }

    public function test_warning_counts_use_sheet_and_row_not_row_number_alone(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('DUK PEGAWAI')->fromArray([['NAMA', 'STATUS', 'TUGAS'], ['Pegawai Utama', null, null]]);
        $book->createSheet()->setTitle('P3K - PPNPN')->fromArray([['NAMA', 'STATUS', 'TUGAS'], ['Tidak cocok', 'PPPK', 'Tambahan']]);
        $path = tempnam(sys_get_temp_dir(), 'sdm');
        try {
            (new Xlsx($book))->save($path);
            $file = UploadedFile::fake()->createWithContent('data.xlsx', file_get_contents($path));
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
        $batch = app(ImportService::class)->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $file);
        $this->assertSame(1, $batch->warning_rows);
        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(1, $batch->summary['primary_warning_rows']);
        $this->assertSame(0, $batch->summary['supplemental_warning_rows']);
    }
}
