<?php

namespace Tests\Feature\Pak;

use App\Domain\Users\AccountStatusService;
use App\Filament\Pak\Resources\PakEventResource\Pages\ListEvents;
use App\Filament\Pak\Support\ActivityDetailPresenter;
use App\Models\PakEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

class ActivityDetailPresentationTest extends PakTestCase
{
    public function test_account_changes_show_recorded_status_reason_and_narrative(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['name' => 'Administrator SDM'])->assignRole('Super Admin');
        $target = User::factory()->create();
        $this->actingAs($admin);
        foreach ([false, true] as $active) {
            app(AccountStatusService::class)->change($admin, $target->fresh(), $active, 'Verifikasi administrator');
            $event = PakEvent::latest('id')->first();
            $detail = app(ActivityDetailPresenter::class)->present($event);
            $this->assertSame([['label' => 'Status Akun', 'before' => $active ? 'Tidak Aktif' : 'Aktif', 'after' => $active ? 'Aktif' : 'Tidak Aktif']], $detail['changes']);
            $this->assertSame('Verifikasi administrator', $detail['reason']);
            $this->assertSame('Akun Pengguna #'.$target->id, $detail['object']);
            $this->assertStringContainsString('Administrator SDM melakukan', $detail['narrative']);
            Livewire::test(ListEvents::class)->mountTableAction('detail', $event)->assertSee('Detail Aktivitas')->assertSee('Waktu Aktivitas')->assertSee('Jenis Aktivitas')->assertSee('Objek Data')->assertSee('Sebelum')->assertSee('Sesudah')->assertSee('Alasan Perubahan')->assertSee('Verifikasi administrator')->assertSee('Tutup');
            $this->assertTrue($event->fresh()->payload === $event->payload);
        }
    }

    public function test_raw_json_is_removed_and_allowed_values_are_escaped(): void
    {
        $event = PakEvent::create(['actor_id' => $this->manager->id, 'subject_type' => 'User', 'subject_id' => $this->manager->id, 'event_code' => 'ACCOUNT_DEACTIVATED', 'occurred_at' => now(), 'payload' => ['before' => ['is_active' => true], 'after' => ['is_active' => false], 'reason' => '<script>alert("x")</script>']]);
        $count = PakEvent::count();
        $html = view('filament.pak.audit-detail', ['detail' => app(ActivityDetailPresenter::class)->present($event)])->render();
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//details | //pre')->length);
        $this->assertStringNotContainsString('Lihat data teknis', $html);
        $this->assertSame(0, $xpath->query('//pre[not(ancestor::details)]')->length);
        $this->assertSame($count, PakEvent::count());
    }

    public function test_missing_null_unknown_and_nested_values_do_not_invent_history(): void
    {
        $event = new PakEvent(['subject_type' => 'UnknownType', 'subject_id' => 47, 'event_code' => 'UNKNOWN_EVENT', 'occurred_at' => now(), 'payload' => ['after' => ['is_active' => null, 'name' => 'Nama Baru'], 'reason' => null]]);
        $detail = app(ActivityDetailPresenter::class)->present($event);
        $this->assertSame('UNKNOWN_EVENT', $detail['activity']);
        $this->assertSame('Objek audit #47', $detail['object']);
        $this->assertSame([], $detail['changes']);
        $this->assertSame('—', $detail['reason']);
        $event->payload = ['before' => ['case' => ['name' => 'Lama']], 'after' => ['case' => ['name' => 'Baru']]];
        $this->assertSame([], app(ActivityDetailPresenter::class)->present($event)['changes']);
        $event->event_code = 'MASTER_SAVED';
        $event->payload = ['after' => ['name' => null, 'private' => ['value' => 'NEVER-EXPOSE']]];
        $this->assertSame([['label' => 'Nama', 'before' => 'Tidak tercatat', 'after' => '—']], app(ActivityDetailPresenter::class)->present($event)['changes']);
        $event->payload = null;
        $this->assertSame([], app(ActivityDetailPresenter::class)->present($event)['changes']);
    }
}
