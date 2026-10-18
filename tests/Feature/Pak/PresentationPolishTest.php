<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Services\PakCaseService;
use App\Filament\Pages\ModuleSelector;
use App\Filament\Pak\Pages\Dashboard;
use App\Filament\Pak\Resources\EmployeeResource;
use App\Filament\Pak\Resources\PakEventResource\Pages\ListEvents;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Support\InternalModuleNavigation;
use App\Models\PakEvent;
use App\Models\Person;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class PresentationPolishTest extends PakTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->manager);
    }

    public function test_selector_uses_accessible_single_link_cards_without_sidebar(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('Super Admin'));
        $html = $this->get(ModuleSelector::getUrl(panel: 'admin'))->assertOk()
            ->assertSee('Pilih modul untuk mengakses layanan kepegawaian.')
            ->assertSee('BBPMP Jawa Timur')->assertSee('Portal Kepegawaian')->assertSee('Selamat Datang')
            ->assertSee('Data pegawai, riwayat, DUK, dan peta jabatan.')->assertSee('Konversi kinerja, akumulasi AK, dan penetapan PAK.')
            ->assertSee('BBPMP Provinsi Jawa Timur • Sistem Informasi Kepegawaian')
            ->assertSee('Manajemen SDM')->assertSee('PAK Konversi')->assertSee('Buka Modul')
            ->assertDontSee('fi-main-sidebar', false)->assertSee('fi-user-menu', false)->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $cards = $xpath->query('//a[contains(@class,"pak-module-card")]');
        $this->assertSame(2, $cards->length);
        foreach ($cards as $card) {
            $this->assertSame(0, $xpath->query('.//a | .//button', $card)->length);
            $this->assertSame(1, $xpath->query('.//svg', $card)->length);
            $this->assertNotEmpty($card->getAttribute('href'));
        }
        $this->actingAs($this->manager);
        $this->get('/admin/modules')->assertRedirect(Dashboard::getUrl(panel: 'admin'));
    }

    public function test_pak_navigation_order_and_labels_are_exact(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('Super Admin'));
        $this->get('/admin/pak')->assertOk();
        $groups = app(InternalModuleNavigation::class)->build()->getNavigation();
        $this->assertSame([null, 'DATA KEPEGAWAIAN', 'REFERENSI PAK', 'PENGELOLAAN PAK', 'AUDIT & AKTIVITAS'], array_map(fn ($g) => $g->getLabel(), $groups));
        $this->assertSame(['Modul Aplikasi', 'Beranda PAK', 'Data Pegawai', 'Kategori JF', 'Jenjang JF', 'Jabatan Fungsional', 'Pemetaan Jabatan dan Jenjang', 'Aturan PAK', 'Draf PAK', 'Riwayat Aktivitas'], collect($groups)->flatMap(fn ($g) => $g->getItems())->map(fn ($i) => $i->getLabel())->all());
        $this->get('/admin/pak/rule-sets')->assertOk()->assertSee('Metadata versi aturan Phase 01; bukan aturan aktif atau engine perhitungan.');
    }

    public function test_dashboard_metrics_follow_existing_queries_and_keep_future_placeholders(): void
    {
        app(PakCaseService::class)->create($this->manager, $this->caseInput());
        Person::factory()->create();
        $this->assertNull($this->person->employeeProfile);
        Livewire::test(Dashboard::class)->assertSee('Beranda PAK Konversi')
            ->assertSee('Kelola data referensi, kelengkapan pegawai, dan proses penyusunan PAK Konversi.')
            ->assertSee('Data pada daftar PAK; bukan ukuran verifikasi atau kelengkapan.')
            ->assertSee('Draf PAK belum merupakan hasil perhitungan atau keputusan kepegawaian.')
            ->assertViewHas('metrics', function ($metrics) {
                $this->assertSame(['Data Pegawai', 'Draf PAK', 'Perlu Pemeriksaan', 'PAK Ditetapkan'], array_column($metrics, 'label'));
                $this->assertSame([EmployeeResource::getEloquentQuery()->count(), 1, '—', '—'], array_column($metrics, 'value'));

                return true;
            });
    }

    public function test_authorized_empty_metrics_are_zero(): void
    {
        $this->person->delete();
        Livewire::test(Dashboard::class)->assertViewHas('metrics', fn ($m) => array_column($m, 'value') === [0, 0, '—', '—']);
    }

    public function test_metrics_without_permissions_do_not_query_protected_counts(): void
    {
        $this->actingAs(User::factory()->create()->givePermissionTo('pak.access'));
        DB::enableQueryLog();
        Livewire::test(Dashboard::class)->assertSee('Tidak memiliki akses')->assertViewHas('metrics', fn ($m) => array_column($m, 'value') === ['—', '—', '—', '—']);
        $counts = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'count(') && preg_match('/people|pak_cases/', $q['query']));
        DB::disableQueryLog();
        $this->assertCount(0, $counts);
    }

    private function event(string $time, string $code = 'PAK_CASE_CREATED', int $subject = 71, ?User $actor = null): PakEvent
    {
        return PakEvent::create(['actor_id' => ($actor ?? $this->manager)->id, 'subject_type' => 'PakCase', 'subject_id' => $subject, 'event_code' => $code, 'occurred_at' => $time, 'payload' => ['reason' => '<script>alert("test")</script>']]);
    }

    public function test_activity_search_type_filter_and_read_only_detail(): void
    {
        $actor = User::factory()->create(['name' => 'Petugas Unik']);
        $a = $this->event('2026-10-17 10:00:00', actor: $actor);
        $b = $this->event('2026-10-18 10:00:00', 'UNMAPPED_EVENT', 999);
        $before = PakEvent::count();
        foreach (['Petugas Unik', 'PAK_CASE_CREATED', '71'] as $search) {
            Livewire::test(ListEvents::class)->searchTable($search)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        }
        Livewire::test(ListEvents::class)->searchTable('PakCase')->assertCanSeeTableRecords([$a, $b]);
        Livewire::test(ListEvents::class)->searchTable('alert')->assertCanNotSeeTableRecords([$a, $b]);
        Livewire::test(ListEvents::class)->filterTable('event_code', 'UNMAPPED_EVENT')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])->assertSee('UNMAPPED_EVENT');
        $page = Livewire::test(ListEvents::class)->assertSee('Periode Aktivitas')->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete');
        $page->mountTableAction('detail', $a)->assertSee('Petugas Unik')->assertSee('Draf PAK dibuat')->assertDontSee('<script>alert(', false)->assertSee('&lt;script&gt;', false);
        $this->assertSame($before, PakEvent::count());
        $this->actingAs(User::factory()->create()->givePermissionTo('pak.access'));
        $this->get('/admin/pak/audit')->assertForbidden();
    }

    public function test_activity_date_filter_includes_local_last_day_and_supports_one_sided_ranges(): void
    {
        $before = $this->event('2026-10-16 23:59:59');
        $start = $this->event('2026-10-17 00:00:00');
        $end = $this->event('2026-10-17 23:59:59');
        $after = $this->event('2026-10-18 00:00:00');
        Livewire::test(ListEvents::class)->filterTable('period', ['from' => '2026-10-17', 'until' => '2026-10-17'])->assertCanSeeTableRecords([$end, $start], inOrder: true)->assertCanNotSeeTableRecords([$before, $after]);
        Livewire::test(ListEvents::class)->filterTable('period', ['from' => null, 'until' => '2026-10-17'])->assertCanSeeTableRecords([$before, $start, $end])->assertCanNotSeeTableRecords([$after]);
        Livewire::test(ListEvents::class)->filterTable('period', ['from' => '2026-10-18', 'until' => null])->assertCanSeeTableRecords([$after])->assertCanNotSeeTableRecords([$before, $start, $end]);
        Livewire::test(ListEvents::class)->filterTable('event_code', 'PAK_CASE_CREATED')->filterTable('period', ['from' => '2026-10-17', 'until' => '2026-10-17'])->assertCountTableRecords(2);
        Livewire::test(ListEvents::class)->filterTable('period', ['from' => '2027-01-01', 'until' => null])->assertCountTableRecords(0);
    }

    public function test_activity_invalid_date_range_cannot_be_applied(): void
    {
        $this->event('2026-10-17 00:00:00');
        Livewire::test(ListEvents::class)->set('tableDeferredFilters.period', ['from' => '2026-10-18', 'until' => '2026-10-17'])->call('applyTableFilters')->assertHasErrors(['tableDeferredFilters.period.until']);
        Livewire::test(ListEvents::class)->filterTable('period', ['from' => 'not-a-date', 'until' => null])->assertCountTableRecords(0);
    }

    public function test_activity_timezone_label_and_boundaries_follow_configuration(): void
    {
        $original = date_default_timezone_get();
        try {
            foreach (['Asia/Jakarta' => 'WIB', 'America/New_York' => 'America/New_York'] as $timezone => $label) {
                config(['app.timezone' => $timezone]);
                date_default_timezone_set($timezone);
                $a = $this->event('2026-11-01 23:59:59', subject: 81);
                $b = $this->event('2026-11-02 00:00:00', subject: 82);
                $page = Livewire::test(ListEvents::class)->filterTable('period', ['from' => '2026-11-01', 'until' => '2026-11-01'])->assertSee('Waktu ('.$label.')')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
                if ($label !== 'WIB') {
                    $page->assertDontSee('WIB');
                }
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_role_badges_and_account_action_colors_preserve_reason_and_audit(): void
    {
        $admin = User::factory()->create()->assignRole('Super Admin');
        $target = User::factory()->create()->assignRole(['Admin PAK', 'Pegawai']);
        $this->actingAs($admin);
        $page = Livewire::test(ListUsers::class);
        $column = $page->instance()->getTable()->getColumn('roles.name');
        foreach (['Super Admin' => 'primary', 'Admin SDM' => 'info', 'Admin PAK' => 'success', 'Internal Viewer' => 'warning', 'Pegawai' => 'gray', 'Unknown' => 'gray'] as $role => $color) {
            $this->assertSame($color, $column->getColor($role));
        }
        $column->record($target);
        $this->assertEqualsCanonicalizing(['Admin PAK', 'Pegawai'], $column->getState());
        $action = $page->instance()->getTable()->getAction('accountStatus')->record($target);
        $this->assertSame('danger', $action->getColor());
        $page->callTableAction('accountStatus', $target, data: ['reason' => ''])->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertTrue($target->fresh()->is_active);
        $page->callTableAction('accountStatus', $target, data: ['reason' => 'Verifikasi nonaktif'])->assertHasNoTableActionErrors();
        $this->assertFalse($target->fresh()->is_active);
        $this->assertDatabaseHas('pak_events', ['event_code' => 'ACCOUNT_DEACTIVATED', 'subject_id' => $target->id]);
        $action->record($target->fresh());
        $this->assertSame('success', $action->getColor());
        Livewire::test(ListUsers::class)->callTableAction('accountStatus', $target->fresh(), data: ['reason' => 'Verifikasi aktif'])->assertHasNoTableActionErrors();
        $this->assertTrue($target->fresh()->is_active);
        $this->assertDatabaseHas('pak_events', ['event_code' => 'ACCOUNT_REACTIVATED', 'subject_id' => $target->id]);
    }
}
