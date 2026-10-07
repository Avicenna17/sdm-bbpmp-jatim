<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportHistory;
use App\Filament\Pages\ManageData;
use App\Models\ImportBatch;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImportNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::factory()->create()->assignRole('Super Admin'));
    }

    private function batch(string $source, ?int $periodId): ImportBatch
    {
        return ImportBatch::create(['source_type' => $source, 'reporting_period_id' => $periodId,
            'uploaded_by' => auth()->id(), 'original_filename' => $source.'.xlsx', 'sha256' => str_repeat('a', 64),
            'path' => 'test.xlsx', 'status' => 'failed']);
    }

    public function test_tabs_show_only_their_own_controls_and_clear_upload_state(): void
    {
        $period = ReportingPeriod::create(['period_month' => '2026-10-01']);
        $duk = $this->batch('PERSONNEL_DUK', $period->id);
        $position = $this->batch('POSITION_REQUIREMENT', null);
        $page = Livewire::test(ManageData::class)
            ->assertSee('Periode DUK Pegawai')->assertDontSee('Status Peta Jabatan / Kebutuhan')
            ->assertDontSee('Dua sumber data, publikasi mandiri')->assertDontSee('Tinjau versi tersimpan')
            ->assertSee('Unduh template DUK')->assertDontSee('Unduh template Peta Jabatan')
            ->call('inspect', $duk->id)->assertSet('batchId', $duk->id)
            ->set('templateMapping', ['name' => 'Nama'])->set('ignoredColumns', 'Catatan')
            ->call('selectSource', 'POSITION_REQUIREMENT')->assertSet('batchId', null)
            ->assertSet('templateMapping', [])->assertSet('ignoredColumns', '')->assertSet('file', null)
            ->assertSet('periodId', $period->id)
            ->assertSee('Status Peta Jabatan / Kebutuhan')->assertDontSee('Periode DUK Pegawai')
            ->assertSee('Unduh template Peta Jabatan')->assertDontSee('Unduh template DUK')
            ->assertSee('POSITION_REQUIREMENT.xlsx')->assertDontSee('PERSONNEL_DUK.xlsx');
        $page->call('inspect', $position->id)->assertSet('source', 'POSITION_REQUIREMENT')
            ->assertSet('periodId', $period->id)->assertSee('Hasil pemeriksaan file')
            ->call('selectSource', 'PERSONNEL_DUK')->assertSet('batchId', null)
            ->assertSee('PERSONNEL_DUK.xlsx')->assertDontSee('POSITION_REQUIREMENT.xlsx');
    }

    public function test_history_source_tabs_filter_records_and_clear_inapplicable_period(): void
    {
        $period = ReportingPeriod::create(['period_month' => '2026-10-01']);
        $duk = $this->batch('PERSONNEL_DUK', $period->id);
        $position = $this->batch('POSITION_REQUIREMENT', null);
        $page = Livewire::test(ImportHistory::class)->assertCanSeeTableRecords([$duk, $position]);
        $this->assertEquals(['' => 2, 'PERSONNEL_DUK' => 1, 'POSITION_REQUIREMENT' => 1], $page->instance()->sourceCounts());
        $page->call('selectSourceFilter', 'PERSONNEL_DUK')->assertCanSeeTableRecords([$duk])->assertCanNotSeeTableRecords([$position])
            ->filterTable('reporting_period_id', $period->id)
            ->call('selectSourceFilter', 'POSITION_REQUIREMENT')->assertCanSeeTableRecords([$position])->assertCanNotSeeTableRecords([$duk])
            ->call('selectSourceFilter', '')->assertCanSeeTableRecords([$duk, $position]);
    }
}
