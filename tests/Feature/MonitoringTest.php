<?php

namespace Tests\Feature;

use App\Domain\Import\ImportService;
use App\Domain\Import\WorkbookParser;
use App\Domain\ReportingPeriod\PublishPeriodService;
use App\Domain\ReportingPeriod\PublishPositionService;
use App\Filament\Pages\ExportData;
use App\Filament\Pages\ManageData;
use App\Filament\Pages\PersonnelData;
use App\Models\ImportBatch;
use App\Models\PersonnelSnapshot;
use App\Models\PositionDataset;
use App\Models\PositionProjectionValue;
use App\Models\PositionRequirementSnapshot;
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
        app(PublishPositionService::class)->publish($this->admin, PositionDataset::current()->draft_batch_id);
        $this->get('/')->assertOk()->assertSee('Komposisi ASN')->assertSee('2033')->assertDontSee('Nama Sangat Rahasia')->assertDontSee('198001012005011001')->assertDontSee('original_filename');
        $this->get('/?employment_group=PPNPN')->assertOk()->assertSee('Komposisi PPNPN');
        $this->get('/?gender=X')->assertOk()->assertSee('Tidak ada data untuk pilihan ini.');
    }

    public function test_public_redesign_uses_real_filtered_projection_data_without_fallbacks(): void
    {
        $this->import([['Private Personnel', '198001012005011001', 'PNS', 'P', 'S1', 'Analis', '7']]);
        $this->import([['Analis', 2, 5, 3, 0, 6], ['Petugas', 1, 2, 1, '', '']], WorkbookParser::POSITION);
        app(PublishPeriodService::class)->publish($this->admin, $this->period);
        app(PublishPositionService::class)->publish($this->admin, PositionDataset::current()->draft_batch_id);
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
        Livewire::test(ExportData::class)
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

    public function test_unchanged_diff_and_projection_history_are_preserved(): void
    {
        $this->import([['Analis', 1, 3, 2, 0, 4]], WorkbookParser::POSITION);
        $batch = $this->import([['Analis', 1, 3, 2, 0, 4], ['Petugas', 2, 4, 2, 1, 4]], WorkbookParser::POSITION);
        $this->assertSame(1, $batch->summary['unchanged']);
        $this->assertSame(1, $batch->summary['new']);
        $this->assertSame(6, PositionProjectionValue::count());
        $this->assertSame(4, PositionProjectionValue::whereHas('snapshot', fn ($query) => $query->where('import_batch_id', $batch->id))->count());
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

    public function test_duk_publishes_without_positions_and_preserves_them_next_month(): void
    {
        $this->import([['Satu', '198001012005011001', 'PNS']]);
        app(PublishPeriodService::class)->publish($this->admin, $this->period);
        $this->assertSame('published', $this->period->fresh()->status);
        $this->assertSame(0, $this->get('/')->assertOk()->viewData('data')['positions']['count']);
        $batch = $this->import([['Global Analis', 2, 5, 3, 1, 6]], WorkbookParser::POSITION);
        $this->assertNull($batch->fresh()->reporting_period_id);
        app(PublishPositionService::class)->publish($this->admin, $batch->id);
        $next = ReportingPeriod::create(['period_month' => '2026-10-01']);
        $this->import([['Dua', '198001012005011002', 'PNS']], period: $next);
        app(PublishPeriodService::class)->publish($this->admin, $next);
        foreach (['2026-09', '2026-10'] as $month) {
            $this->assertSame(5, $this->get('/?period='.$month)->assertOk()->viewData('data')['positions']['requirements']);
        }
    }

    public function test_positions_publish_without_any_period_and_draft_does_not_leak(): void
    {
        $this->period->delete();
        $service = app(ImportService::class);
        $file = $this->file([['Global Analis', 2, 5, 3, 1, 6]], WorkbookParser::POSITION);
        $first = $service->preview($this->admin, null, WorkbookParser::POSITION, $file);
        $service->commit($this->admin, $first);
        $this->assertNull($this->get('/')->assertOk()->viewData('data'));
        $publish = app(PublishPositionService::class);
        $publish->publish($this->admin, $first->id);
        $this->assertSame(5, $this->get('/')->assertOk()->viewData('data')['positions']['requirements']);
        $second = $service->preview($this->admin, null, WorkbookParser::POSITION, $this->file([['Versi Baru', 3, 9, 6, 0, 9]], WorkbookParser::POSITION));
        $service->commit($this->admin, $second);
        $this->assertSame(5, $this->get('/')->assertOk()->viewData('data')['positions']['requirements']);
        $this->actingAs($this->admin);
        $csv = $this->get('/exports/positions/csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Global Analis', $csv);
        $this->assertStringNotContainsString('Versi Baru', $csv);
        $this->get('/exports/positions/xlsx')->assertOk();
        $publish->publish($this->admin, $second->id);
        $this->assertSame(9, $this->get('/')->assertOk()->viewData('data')['positions']['requirements']);
        $this->assertSame(0, ReportingPeriod::count());
        $this->assertSame(2, PositionRequirementSnapshot::count());
    }

    public function test_duk_commit_does_not_invalidate_position_preview(): void
    {
        $service = app(ImportService::class);
        $batch = $service->preview($this->admin, null, WorkbookParser::POSITION, $this->file([['Analis', 2, 5, 3, 1, 6]], WorkbookParser::POSITION));
        $this->import([['Satu', '198001012005011001', 'PNS']]);
        $service->commit($this->admin, $batch);
        $this->assertSame('committed', $batch->fresh()->status);
    }

    public function test_stale_position_preview_and_publication_are_rejected(): void
    {
        $service = app(ImportService::class);
        $first = $service->preview($this->admin, null, WorkbookParser::POSITION, $this->file([['Lama', 2, 5, 3]], WorkbookParser::POSITION));
        $second = $this->import([['Baru', 2, 5, 3]], WorkbookParser::POSITION);
        try {
            $service->commit($this->admin, $first);
            $this->fail('Stale position preview must fail.');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        app(PublishPositionService::class)->publish($this->admin, $first->id);
    }

    public function test_admin_can_import_and_publish_positions_without_selecting_period(): void
    {
        $this->period->delete();
        $this->actingAs($this->admin);
        $page = Livewire::test(ManageData::class)
            ->set('source', WorkbookParser::POSITION)
            ->set('file', $this->file([['Analis Mandiri', 2, 5, 3]], WorkbookParser::POSITION))
            ->call('preview')->assertHasNoErrors()
            ->call('saveImport')->assertHasNoErrors();
        $id = PositionDataset::current()->draft_batch_id;
        $page->call('publishPositions', $id)->assertHasNoErrors();
        $this->assertSame($id, PositionDataset::current()->published_batch_id);
        Livewire::test(ExportData::class)->set('source', 'positions')->call('download')->assertHasNoErrors()->assertRedirect();
        $viewer = User::factory()->create()->assignRole('Internal Viewer');
        $this->actingAs($viewer);
        Livewire::test(ManageData::class)->call('publishPositions', $id)->assertForbidden();
    }

    public function test_admin_sdm_can_publish_replacement_positions_without_revising_duk(): void
    {
        $user = User::factory()->create()->assignRole('Admin SDM');
        $publish = app(PublishPositionService::class);
        $first = $this->import([['Awal', 1, 3, 2]], WorkbookParser::POSITION);
        $publish->publish($user, $first->id);
        $duk = app(ImportService::class)->preview($this->admin, $this->period, WorkbookParser::PERSONNEL, $this->file([['Satu', '198001012005011001', 'PNS']]));
        $second = $this->import([['Baru', 2, 3, 1]], WorkbookParser::POSITION);
        $publish->publish($user, $second->id);
        app(ImportService::class)->commit($this->admin, $duk);
        $this->assertSame($second->id, PositionDataset::current()->published_batch_id);
        $this->assertSame('committed', $duk->fresh()->status);
        $this->assertFalse($user->can('period.revise'));
    }

    public function test_legacy_position_preview_requires_revalidation_without_changing_audit(): void
    {
        $service = app(ImportService::class);
        $old = $service->preview($this->admin, null, WorkbookParser::POSITION, $this->file([['Analis', 1, 3, 2]], WorkbookParser::POSITION));
        $old->update(['summary' => array_diff_key($old->summary, ['position_policy' => true])]);
        try {
            $service->commit($this->admin, $old);
            $this->fail('Legacy preview must be revalidated.');
        } catch (ValidationException) {
        }
        $new = $service->revalidate($this->admin, $old);
        $service->commit($this->admin, $new);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('validated', $old->fresh()->status);
        $this->assertSame('committed', $new->fresh()->status);
    }

    public function test_active_duk_and_archive_labels_preserve_exports_and_prevent_republication(): void
    {
        $this->import([['Arsip Pegawai', '198001012005011001', 'PNS']]);
        $publisher = app(PublishPeriodService::class);
        $publisher->publish($this->admin, $this->period);
        $revision = $this->period->fresh()->revision;
        $publisher->publish($this->admin, $this->period);
        $this->assertSame($revision, $this->period->fresh()->revision);
        $next = ReportingPeriod::create(['period_month' => '2026-10-01']);
        $this->import([['Pegawai Aktif', '198001012005011002', 'PNS']], period: $next);
        $this->assertTrue($next->fresh()->canPublish(ReportingPeriod::activePublished()));
        $publisher->publish($this->admin, $next);
        $active = ReportingPeriod::activePublished();
        $this->assertSame('Arsip', $this->period->fresh()->publicationLabel($active));
        $this->assertSame('Aktif di dashboard', $next->fresh()->publicationLabel($active));
        $this->assertFalse($this->period->fresh()->canPublish($active));
        $this->assertFalse($next->fresh()->canPublish($active));
        $this->actingAs($this->admin);
        $csv = $this->get('/exports/personnel/csv?period_id='.$this->period->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('Arsip Pegawai', $csv);
        Livewire::test(ManageData::class)->set('periodId', $this->period->id)->assertSee('Arsip')->assertSee('Terakhir dipublikasikan');
        $this->expectException(ValidationException::class);
        $publisher->publish($this->admin, $this->period);
    }

    public function test_position_export_can_select_published_history_but_never_drafts(): void
    {
        $first = $this->import([['Jabatan Lama', 1, 3, 2, 1, 4]], WorkbookParser::POSITION);
        $publisher = app(PublishPositionService::class);
        $publisher->publish($this->admin, $first->id);
        $second = $this->import([['Jabatan Aktif', 2, 4, 2, 0, 5]], WorkbookParser::POSITION);
        $publisher->publish($this->admin, $second->id);
        $draft = $this->import([['Jabatan Draft', 3, 5, 2, 0, 6]], WorkbookParser::POSITION);
        $this->actingAs($this->admin);
        $old = $this->get('/exports/positions/csv?position_version_id='.$first->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('Jabatan Lama', $old);
        $this->assertStringNotContainsString('Jabatan Aktif', $old);
        $current = $this->get('/exports/positions/csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Jabatan Aktif', $current);
        $this->assertStringNotContainsString('Jabatan Draft', $current);
        $this->get('/exports/positions/xlsx?position_version_id='.$first->id)->assertOk();
        $this->get('/exports/positions/csv?position_version_id='.$draft->id)->assertStatus(422);
        $duk = $this->import([['Pegawai', '198001012005011001', 'PNS']]);
        $this->get('/exports/positions/csv?position_version_id='.$duk->id)->assertStatus(422);
        Livewire::test(ExportData::class)->set('source', 'positions')->assertSet('positionVersionId', $second->id)
            ->assertSee('Versi '.$first->fresh()->position_version)->assertSee('Arsip')->set('positionVersionId', $first->id)
            ->call('download')->assertRedirect(route('exports', ['source' => 'positions', 'format' => 'xlsx', 'position_version_id' => $first->id]));
    }

    public function test_position_versions_count_only_saved_position_imports(): void
    {
        $this->import([['Pegawai', '198001012005011001', 'PNS']]);
        $first = $this->import([['Jabatan Satu', 1, 3, 2]], WorkbookParser::POSITION);
        $this->assertSame(1, $first->fresh()->position_version);
        $this->assertGreaterThan(1, $first->id);
        app(ImportService::class)->commit($this->admin, $first);
        $preview = app(ImportService::class)->preview($this->admin, null, WorkbookParser::POSITION, $this->file([['Preview', 2, 4, 2]], WorkbookParser::POSITION));
        $this->assertNull($preview->position_version);
        $this->import([['Pegawai Dua', '198001012005011002', 'PNS']]);
        $second = $this->import([['Jabatan Dua', 2, 4, 2]], WorkbookParser::POSITION);
        $this->assertSame(2, $second->fresh()->position_version);
        app(PublishPositionService::class)->publish($this->admin, $second->id);
        $this->actingAs($this->admin);
        $response = $this->get('/exports/positions/csv')->assertOk();
        $this->assertStringContainsString('_versi-2_', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Versi 2', $response->streamedContent());
        Livewire::test(ManageData::class)->call('selectSource', WorkbookParser::POSITION)->assertSee('Aktif di dashboard');
    }
}
