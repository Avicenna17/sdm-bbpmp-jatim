<?php

namespace Tests\Feature;

use App\Domain\Import\ImportService;
use App\Domain\Import\WorkbookParser;
use App\Domain\Templates\TemplateService;
use App\Filament\Pages\Templates;
use App\Models\ImportTemplate;
use App\Models\PersonnelSnapshot;
use App\Models\ReportingPeriod;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->actingAs($this->admin);
        app(TemplateService::class)->ensureTemplates();
    }

    private function version(string $source = 'PERSONNEL_DUK')
    {
        $service = app(TemplateService::class);
        $t = ImportTemplate::where('source', $source)->firstOrFail();
        $d = $service->defaults($source);
        $d['columns'][1]['label'] = 'Nama Lengkap Pegawai';
        $d['columns'][] = ['key' => 'extra:12345678', 'label' => 'Nomor SK', 'type' => 'text', 'required' => true, 'width' => 24, 'color' => '#FFFFFF', 'bold' => false, 'align' => 'left', 'choices' => ''];
        $v = $service->save($t, $d, null, $this->admin->id);
        $service->activate($v);

        return $v->fresh();
    }

    private function file($version, array $values = [], ?string $renamed = null, bool $unknown = false): UploadedFile
    {
        $book = app(TemplateService::class)->workbook($version);
        $sheet = $book->getActiveSheet();
        foreach ($version->definition['columns'] as $i => $c) {
            $sheet->setCellValueExplicit([$i + 1, 4], (string) ($values[$c['key']] ?? match ($c['key']) {
                'name_at_period' => 'Pegawai Contoh','employment_status' => 'PPNPN','position_name' => 'Jabatan Contoh','incumbent_count' => '2','requirement_count' => '4','extra:12345678' => 'SK-001',default => ''
            }), 's');
            if ($renamed && $c['key'] === 'name_at_period') {
                $sheet->setCellValue([$i + 1, 3], $renamed);
            }
        }
        if ($unknown) {
            $sheet->setCellValue([count($version->definition['columns']) + 1, 3], 'Asing');
        }
        $path = tempnam(sys_get_temp_dir(), 'template');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $content = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('template.xlsx', $content);
    }

    public function test_renamed_headers_extra_columns_commit_and_export(): void
    {
        $v = $this->version();
        $period = ReportingPeriod::create(['period_month' => '2026-10-01', 'status' => 'draft']);
        $batch = app(ImportService::class)->preview($this->admin, $period, WorkbookParser::PERSONNEL, $this->file($v));
        $this->assertSame('validated', $batch->status, $batch->issues->pluck('message')->join(';'));
        $this->assertSame($v->id, $batch->template_version_id);
        app(ImportService::class)->commit($this->admin, $batch);
        $this->assertSame('SK-001', $period->personnel()->first()->extra_data['extra:12345678']['value']);
        $period->update(['status' => 'published']);
        $response = $this->get('/exports/personnel/csv?period_id='.$period->id)->assertOk();
        $this->assertStringContainsString('SK-001', $response->streamedContent());
    }

    public function test_explicit_mapping_and_unknown_columns_are_not_silently_ignored(): void
    {
        $v = $this->version();
        $file = $this->file($v, [], 'Nama di Excel', true);
        try {
            app(WorkbookParser::class)->parse($file->getRealPath(), WorkbookParser::PERSONNEL, $v->id, ['name_at_period' => 'Nama di Excel']);
            $this->fail('Unknown column must block');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('belum dikenali', $e->getMessage());
        }
        $parsed = app(WorkbookParser::class)->parse($file->getRealPath(), WorkbookParser::PERSONNEL, $v->id, ['name_at_period' => 'Nama di Excel'], ['Asing']);
        $this->assertSame('Pegawai Contoh', $parsed['rows'][0]['name_at_period']);
        $this->assertSame(['Asing'], $parsed['summary']['ignored_columns']);
    }

    public function test_old_versions_are_immutable_and_still_readable(): void
    {
        $v = $this->version();
        $file = $this->file($v);
        $service = app(TemplateService::class);
        $d = $v->definition;
        $d['columns'][1]['label'] = 'Nama Versi Kedua';
        $new = $service->save($v->template, $d, null, $this->admin->id);
        $service->activate($new);
        $this->assertSame('Nama Lengkap Pegawai', $v->fresh()->definition['columns'][1]['label']);
        $parsed = app(WorkbookParser::class)->parse($file->getRealPath(), WorkbookParser::PERSONNEL);
        $this->assertSame($v->id, $parsed['summary']['template_version_id']);
        $this->expectException(ModelNotFoundException::class);
        $service->save($v->template, $d, $v->id, $this->admin->id);
    }

    public function test_required_and_duplicate_columns_are_rejected(): void
    {
        $service = app(TemplateService::class);
        $d = $service->defaults(WorkbookParser::PERSONNEL);
        $d['columns'] = array_values(array_filter($d['columns'], fn ($c) => $c['key'] !== 'name_at_period'));
        $this->expectException(ValidationException::class);
        $service->validate(WorkbookParser::PERSONNEL, $d);
    }

    public function test_additional_required_values_and_dynamic_years(): void
    {
        $v = $this->version(WorkbookParser::POSITION);
        $file = $this->file($v, ['extra:12345678' => '']);
        $parsed = app(WorkbookParser::class)->parse($file->getRealPath(), WorkbookParser::POSITION);
        $this->assertNotEmpty(array_filter($parsed['issues'], fn ($i) => $i['code'] === 'INVALID_TEMPLATE_VALUE'));
        $this->assertCount(12, $parsed['rows'][0]['projections']);
    }

    public function test_template_editor_and_viewer_permissions(): void
    {
        Livewire::test(Templates::class)->assertSee('Preview template')->call('save')->assertHasNoErrors();
        $viewer = User::factory()->create();
        $viewer->assignRole('Internal Viewer');
        $this->actingAs($viewer);
        Livewire::test(Templates::class)->assertSee('Preview template')->call('save')->assertForbidden();
    }

    public function test_draft_is_testable_but_not_accepted_for_import(): void
    {
        $service = app(TemplateService::class);
        $template = ImportTemplate::where('source', WorkbookParser::PERSONNEL)->firstOrFail();
        $version = $service->save($template, $service->defaults(WorkbookParser::PERSONNEL), null, $this->admin->id);
        $file = $this->file($version);
        $result = app(WorkbookParser::class)->parse($file->getRealPath(), WorkbookParser::PERSONNEL, $version->id, [], [], true);
        $this->assertCount(1, $result['rows']);
        $this->assertSame(0, PersonnelSnapshot::count());
        $this->expectException(\RuntimeException::class);
        app(WorkbookParser::class)->parse($file->getRealPath(), WorkbookParser::PERSONNEL);
    }

    public function test_duplicate_names_and_invalid_custom_values_are_rejected(): void
    {
        $service = app(TemplateService::class);
        $v = $this->version();
        $d = $v->definition;
        $d['columns'][count($d['columns']) - 1]['type'] = 'date';
        $next = $service->save($v->template, $d, null, $this->admin->id);
        $service->activate($next);
        $invalidFile = $this->file($next, ['extra:12345678' => '2026-02-30']);
        $result = app(WorkbookParser::class)->parse($invalidFile->getRealPath(), WorkbookParser::PERSONNEL);
        $this->assertNotEmpty(array_filter($result['issues'], fn ($i) => $i['code'] === 'INVALID_TEMPLATE_VALUE'));
        $d['columns'][0]['label'] = $d['columns'][1]['label'];
        $this->expectException(ValidationException::class);
        $service->validate(WorkbookParser::PERSONNEL, $d);
    }

    public function test_required_defaults_and_new_column_editor(): void
    {
        $page = Livewire::test(Templates::class)->assertSet('showAddColumn', false);
        $definition = $page->get('definition');
        foreach ($definition['columns'] as $index => $column) {
            if (in_array($column['key'], ['name_at_period', 'employment_status'])) {
                $this->assertTrue($column['required']);
                $page->set('definition.columns.'.$index.'.required', false);
            }
        }
        $page->call('save')->assertHasNoErrors();
        $stored = \App\Models\ImportTemplateVersion::findOrFail($page->get('draftId'));
        foreach ($stored->definition['columns'] as $column) {
            if (in_array($column['key'], ['name_at_period', 'employment_status'])) {
                $this->assertTrue($column['required']);
            }
        }
        $page->set('showAddColumn', true)->set('newType', 'number')->call('addColumn')->assertSet('showAddColumn', false)->assertHasNoErrors();
        $columns = $page->get('definition')['columns'];
        $this->assertSame('number', end($columns)['type']);
    }

    public function test_inline_column_cancellation_and_live_preview_preserve_active_version(): void
    {
        $version = $this->version();
        $page = Livewire::test(Templates::class)
            ->assertSee('Aktif / Sedang Digunakan')
            ->assertSee('Draft / Masih dalam Proses')
            ->set('definition.columns.1.label', 'Nama dalam draft')
            ->assertSee('Nama dalam draft')
            ->assertSee('Nama Lengkap Pegawai');
        $this->assertSame('Nama Lengkap Pegawai', $version->fresh()->definition['columns'][1]['label']);
        $count = count($page->get('definition')['columns']);
        $page->call('startColumn')->assertHasNoErrors();
        $key = $page->get('definition')['columns'][$count]['key'];
        $this->assertContains($key, $page->get('pendingKeys'));
        $page->call('changePurpose', $count, 'assignment_detail')->assertHasNoErrors();
        $this->assertSame('assignment_detail', $page->get('definition')['columns'][$count]['key']);
        $page->call('moveColumn', $count, -1)->call('removeColumn', $count - 1)->assertHasNoErrors();
        $this->assertCount($count, $page->get('definition')['columns']);
        $this->assertSame([], $page->get('pendingKeys'));
        $page->call('startColumn')->call('save')->assertHasNoErrors()->assertSet('pendingKeys', []);
    }

    public function test_initial_version_and_white_draft_preserve_saved_colors(): void
    {
        $service = app(TemplateService::class);
        $service->ensureTemplates();
        $template = ImportTemplate::where('source', WorkbookParser::PERSONNEL)->firstOrFail();
        $this->assertSame(1, $template->versions()->count());
        $this->assertSame(1, $template->activeVersion->number);
        $this->assertNotNull($template->activeVersion->activated_at);
        $page = Livewire::test(Templates::class);
        $this->assertSame('#FFFFFF', $page->get('definition')['columns'][0]['color']);
        $this->assertSame('#E2EFDA', $template->activeVersion->definition['columns'][0]['color']);
        $page->set('definition.columns.0.color', '#123456')->call('save')->assertHasNoErrors();
        $id = $page->get('draftId');
        $page->call('open', $template->id)->call('loadVersion', $id);
        $this->assertSame('#123456', $page->get('definition')['columns'][0]['color']);
        $this->assertSame(2, $template->versions()->findOrFail($id)->number);
        $service->ensureTemplates();
        $this->assertSame(2, $template->versions()->count());
    }

    public function test_bulk_appearance_only_updates_draft_presentation(): void
    {
        $page = Livewire::test(Templates::class);
        $before = $page->get('definition')['columns'];
        $page->set('definition.columns.0.color', '#123456')
            ->set('definition.columns.0.width', 32)
            ->set('definition.columns.0.bold', false)
            ->set('definition.columns.0.align', 'right')
            ->call('applyAppearanceToAll', 0)->assertHasNoErrors();
        foreach ($page->get('definition')['columns'] as $index => $column) {
            $this->assertSame('#123456', $column['color']);
            $this->assertEquals(32, $column['width']);
            $this->assertFalse($column['bold']);
            $this->assertSame('right', $column['align']);
            $this->assertSame($before[$index]['key'], $column['key']);
            $this->assertSame($before[$index]['label'], $column['label']);
            $this->assertSame($before[$index]['required'], $column['required']);
        }
        $template = ImportTemplate::findOrFail($page->get('templateId'));
        $this->assertSame('#E2EFDA', $template->activeVersion->definition['columns'][0]['color']);
        $viewer = User::factory()->create();
        $viewer->assignRole('Internal Viewer');
        $this->actingAs($viewer);
        Livewire::test(Templates::class)->call('applyAppearanceToAll', 0)->assertForbidden();
    }

    public function test_sample_upload_loads_draft_and_cancellation_restores_previous_state(): void
    {
        $version = $this->version();
        $page = Livewire::test(Templates::class)->set('definition.title', 'Draft sebelum unggahan');
        $before = $page->get('definition');
        $page->set('sampleFile', $this->file($version))->call('testSample')->assertHasNoErrors();
        $this->assertSame([], $page->get('sampleResult')['errors']);
        $page->assertNotified('File lolos pemeriksaan struktur')->assertSee('Pegawai Contoh');
        $this->assertSame($version->definition['title'], $page->get('definition')['title']);
        $this->assertSame('SK-001', $page->get('sampleRows')[0]['extra:12345678']);
        $this->assertSame(0, PersonnelSnapshot::count());
        $loaded = $page->get('definition');
        $page->set('sampleFile', $this->file($version, [], null, true))->call('testSample')
            ->assertNotified('File belum sesuai');
        $this->assertEquals($loaded, $page->get('definition'));
        $page->call('cancelSample')->assertSet('sampleBackup', null);
        $this->assertSame($before, $page->get('definition'));
        $this->assertSame([], $page->get('sampleRows'));
        $page->set('sampleFile', $this->file($version))->call('testSample')->call('save')->assertHasNoErrors()->assertSet('sampleBackup', null);
        $this->assertSame(0, PersonnelSnapshot::count());
    }

    public function test_export_filename_matches_document_header(): void
    {
        $version = $this->version();
        $period = ReportingPeriod::create(['period_month' => '2026-10-01', 'status' => 'draft']);
        $batch = app(ImportService::class)->preview($this->admin, $period, WorkbookParser::PERSONNEL, $this->file($version));
        app(ImportService::class)->commit($this->admin, $batch);
        $period->update(['status' => 'published']);
        $response = $this->get('/exports/personnel/xlsx?period_id='.$period->id)->assertOk();
        $expected = $version->definition['title'].'_'.$period->label.'.xlsx';
        $response->assertDownload($expected);
        $path = tempnam(sys_get_temp_dir(), 'export');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $this->assertSame($version->definition['title'], $book->getActiveSheet()->getCell('A1')->getValue());
            $this->assertSame($period->label, $book->getActiveSheet()->getCell('A2')->getValue());
            $this->assertSame('Pegawai Contoh', $book->getActiveSheet()->getCell('C4')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_empty_template_structure_can_be_loaded_without_employee_data(): void
    {
        $version = $this->version();
        $book = app(TemplateService::class)->workbook($version);
        $path = tempnam(sys_get_temp_dir(), 'sample');
        try {
            (new Xlsx($book))->save($path);
            $loaded = app(\App\Domain\Templates\TemplateSampleLoader::class)->load($path, WorkbookParser::PERSONNEL, $version->id);
            $this->assertSame([], $loaded['samples']);
            $this->assertSame(0, $loaded['rows']);
            $this->assertSame($version->definition['title'], $loaded['definition']['title']);
            $this->assertSame(0, PersonnelSnapshot::count());
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_sample_structure_allows_optional_columns_to_be_absent(): void
    {
        foreach ([WorkbookParser::PERSONNEL, WorkbookParser::POSITION] as $source) {
            $version = ImportTemplate::where('source', $source)->firstOrFail()->activeVersion;
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet()->setTitle($source === WorkbookParser::PERSONNEL ? 'DUK PEGAWAI' : 'Peta Jabatan Kebutuhan');
            $sheet->fromArray($source === WorkbookParser::PERSONNEL ? [['NAMA', 'STATUS'], ['Contoh', null]] : [['Nama Jabatan', 'Jumlah Pemangku', 'Jumlah Kebutuhan'], ['Contoh', 0, 2]]);
            $path = tempnam(sys_get_temp_dir(), 'structure');
            try {
                (new Xlsx($book))->save($path);
                $result = app(\App\Domain\Templates\TemplateSampleLoader::class)->load($path, $source, $version->id);
                $this->assertCount($source === WorkbookParser::PERSONNEL ? 2 : 3, $result['definition']['columns']);
                $this->assertSame(1, $result['rows']);
            } finally {
                unlink($path);
                $book->disconnectWorksheets();
            }
        }
    }

    public function test_user_testing_workbooks_when_available(): void
    {
        $paths = [WorkbookParser::PERSONNEL => getenv('SDM_TEST_DUK_SAMPLE'), WorkbookParser::POSITION => getenv('SDM_TEST_POSITION_SAMPLE')];
        if (! array_filter($paths)) {
            $this->markTestSkipped('Optional local workbooks not supplied.');
        }
        foreach ($paths as $source => $path) {
            $version = ImportTemplate::where('source', $source)->firstOrFail()->activeVersion;
            $result = app(\App\Domain\Templates\TemplateSampleLoader::class)->load($path, $source, $version->id);
            $this->assertCount($source === WorkbookParser::PERSONNEL ? 6 : 9, $result['definition']['columns']);
            $this->assertGreaterThan(0, $result['rows']);
            $this->assertNotEmpty($result['samples']);
        }
    }

    public function test_sample_upload_enables_button_and_exposes_draft_actions(): void
    {
        $version = $this->version();
        $page = Livewire::test(Templates::class);
        $this->assertStringNotContainsString('wire:target="sampleFile,testSample"', $page->html());
        $page->set('sampleFile', $this->file($version))->assertSee('File siap diuji:');
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $buttons = (new \DOMXPath($dom))->query('//button[normalize-space(.)="Uji file contoh"]');
        $this->assertSame(1, $buttons->length);
        $this->assertFalse($buttons->item(0)->hasAttribute('disabled'));
        $this->assertSame('testSample', $buttons->item(0)->getAttribute('wire:click'));
        $this->assertSame('testSample', $buttons->item(0)->getAttribute('wire:target'));
        $page->call('testSample')->assertHasNoErrors()->assertDispatched('template-sample-loaded')
            ->assertSee('Pegawai Contoh')->assertSee('Batal')->assertSee('Simpan draft');
        $this->assertNotNull($page->get('sampleBackup'));
        $page->call('cancelSample')->assertSet('sampleBackup', null);
    }

    public function test_unmarked_files_require_explicit_choice_after_activation(): void
    {
        $this->version();
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('DUK PEGAWAI')->fromArray([['NO', 'NAMA', 'STATUS'], ['1', 'Contoh', 'PPNPN']]);
        $path = tempnam(sys_get_temp_dir(), 'legacy');
        (new Xlsx($book))->save($path);
        try {
            $period = ReportingPeriod::create(['period_month' => '2026-10-01', 'status' => 'draft']);
            $file = new UploadedFile($path, 'legacy.xlsx', null, null, true);
            $batch = app(ImportService::class)->preview($this->admin, $period, WorkbookParser::PERSONNEL, $file);
            $this->assertSame('failed', $batch->status);
            $this->assertStringContainsString('tidak memiliki penanda', $batch->issues->first()->message);
            $batch = app(ImportService::class)->preview($this->admin, $period, WorkbookParser::PERSONNEL, $file, 0);
            $this->assertSame('validated', $batch->status);
            $this->assertSame('validated', app(ImportService::class)->revalidate($this->admin, $batch)->status);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }
}
