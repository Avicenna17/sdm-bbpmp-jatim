<?php

namespace Tests\Feature\Pak;

use App\Filament\Auth\Login;
use App\Filament\Pages\ManageData;
use App\Filament\Pages\ModuleSelector;
use App\Filament\Pak\Pages\Dashboard;
use App\Filament\Pak\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Pak\Resources\FunctionalPositionResource\Pages\ManageFunctionalPosition;
use App\Filament\Resources\RoleResource;
use App\Filament\Support\InternalIntendedUrl;
use App\Filament\Support\InternalModuleNavigation;
use App\Models\ReportingPeriod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class InternalWorkspaceTest extends PakTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function loginRole(string $role): User
    {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_role_matrix_landing_and_navigation(): void
    {
        foreach (['Admin SDM', 'Internal Viewer'] as $role) {
            $this->loginRole($role);
            $this->get('/admin')->assertRedirect(ManageData::getUrl(panel: 'admin'));
            $this->get('/admin/modules')->assertRedirect(ManageData::getUrl(panel: 'admin'));
            $this->get('/admin/manage-data')->assertOk()->assertDontSee('Ganti Modul')->assertDontSee('Data Pegawai PAK');
            $this->get('/admin/pak')->assertForbidden();
        }
        $this->actingAs($this->manager);
        $this->get('/admin')->assertRedirect(Dashboard::getUrl(panel: 'admin'));
        $this->get('/admin/pak')->assertOk()->assertSee('Data Pegawai PAK')->assertDontSee('Ganti Modul')->assertDontSee('Peta Jabatan / Kebutuhan');
        foreach (['manage-data', 'personnel-data', 'position-data', 'import-history', 'export-data', 'templates', 'users', 'roles'] as $slug) {
            $this->get('/admin/'.$slug)->assertForbidden();
        }
        $this->manager->assignRole('Pegawai');
        $this->get('/admin/pak')->assertOk();
        $this->loginRole('Pegawai');
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/modules')->assertForbidden();
    }

    public function test_super_admin_selector_and_actions_in_both_modules_share_session(): void
    {
        $user = $this->loginRole('Super Admin');
        $this->get('/admin')->assertRedirect(ModuleSelector::getUrl(panel: 'admin'));
        $this->get('/admin/modules')->assertOk()->assertSee('Buka SDM')->assertSee('Buka PAK Konversi');
        $this->get('/admin/manage-data')->assertOk()->assertSee('Ganti Modul')->assertDontSee('Data Pegawai PAK');
        $this->get('/admin/pak')->assertOk()->assertSee('Ganti Modul')->assertDontSee('Periode dan Import Data');
        Livewire::test(ManageData::class)->set('month', '2027-01')->call('createPeriod')->assertHasNoErrors();
        Livewire::test(ManageFunctionalPosition::class)->callAction('create', ['code' => 'WORKSPACE', 'name' => 'JF Sintetis', 'is_active' => true])->assertHasNoActionErrors();
        $this->assertSame('2027-01-01', ReportingPeriod::latest('id')->first()->period_month->format('Y-m-d'));
        $this->assertDatabaseHas('functional_positions', ['code' => 'WORKSPACE']);
        $this->get('/admin/modules')->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(session()->has('active_module'));
    }

    public function test_each_workspace_has_exactly_one_home_navigation_item(): void
    {
        $this->loginRole('Super Admin');
        foreach ([ManageData::class => 'Periode dan Import Data', Dashboard::class => 'Beranda PAK'] as $page => $label) {
            $home = $page::getUrl(panel: 'admin');
            $this->get($home)->assertOk();
            $items = collect(app(InternalModuleNavigation::class)->build()->getNavigation())
                ->flatMap(fn ($group) => $group->getItems());
            $homeItems = $items->filter(fn ($item) => $item->getUrl() === $home);
            $this->assertCount(1, $homeItems);
            $this->assertSame($label, $homeItems->first()->getLabel());
            $this->assertCount(1, $items->filter(fn ($item) => $item->getLabel() === 'Ganti Modul'));
        }
    }

    public function test_direct_permissions_and_partial_module_access(): void
    {
        $user = User::factory()->create()->givePermissionTo('pak.access');
        $this->actingAs($user);
        $this->get('/admin')->assertRedirect(Dashboard::getUrl(panel: 'admin'));
        $this->get('/admin/pak')->assertOk()->assertDontSee('Data Pegawai PAK');
        $this->get('/admin/pak/employees')->assertForbidden();
        $user->givePermissionTo('personnel.view');
        $this->get('/admin/personnel-data')->assertForbidden();
        $user->givePermissionTo('period.view');
        $this->get('/admin/modules')->assertOk()->assertSee('Buka SDM')->assertSee('Buka PAK Konversi');
        $this->get('/admin/personnel-data')->assertOk();
    }

    public function test_login_redirects_by_module_count_without_intended_url(): void
    {
        foreach (['Admin SDM' => ManageData::getUrl(panel: 'admin'), 'Admin PAK' => Dashboard::getUrl(panel: 'admin'), 'Super Admin' => ModuleSelector::getUrl(panel: 'admin')] as $role => $url) {
            auth()->logout();
            $user = User::factory()->create()->assignRole($role);
            Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate')->assertRedirect($url);
            $this->assertAuthenticatedAs($user);
        }
        auth()->logout();
        $user = User::factory()->create()->assignRole('Pegawai');
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    private function intended(User $actor, string $url): string
    {
        $this->actingAs($actor);
        session()->put('url.intended', $url);
        $result = app(InternalIntendedUrl::class)->destination(request(), $actor);
        $this->assertFalse(session()->has('url.intended'));

        return $result;
    }

    public function test_intended_url_rejects_external_unknown_auth_and_unauthorized_routes(): void
    {
        $this->actingAs($this->manager)->get('/admin/pak')->assertOk();
        $fallback = Dashboard::getUrl(panel: 'admin');
        foreach (['https://evil.example/admin/pak', '//evil.example/admin/pak', '/pak/login', '/admin/login', '/admin/logout', '/admin/unknown', '/admin/manage-data', '/admin/users', '/admin/pak/cases/999999/edit', 'https://localhost/admin/pak', '/admin\\pak', '/admin/pak#fragment', 'admin/pak', '?query=only', 'http://['] as $url) {
            $this->assertSame($fallback, $this->intended($this->manager, $url), $url);
        }
        $valid = url('/admin/pak/employees').'?tableSearch=Test';
        $this->assertSame($valid, $this->intended($this->manager, $valid));
        $this->assertSame(url('/admin/pak/employees'), $this->intended($this->manager, '/admin/pak/employees'));
    }

    public function test_intended_record_uses_specific_record_authorization(): void
    {
        $super = $this->loginRole('Super Admin');
        $this->get('/admin/modules')->assertOk();
        $protectedRole = Role::findByName('Super Admin');
        $allowedRole = Role::findByName('Admin PAK');
        $denied = RoleResource::getUrl('edit', ['record' => $protectedRole], panel: 'admin');
        $allowed = RoleResource::getUrl('edit', ['record' => $allowedRole], panel: 'admin');
        $this->assertSame(ModuleSelector::getUrl(panel: 'admin'), $this->intended($super, $denied));
        $this->assertSame($allowed, $this->intended($super, $allowed));
        $this->get($denied)->assertForbidden();
    }

    public function test_login_consumes_authorized_intended_and_falls_back_otherwise(): void
    {
        foreach ([url('/admin/pak/employees') => url('/admin/pak/employees'), url('/admin/manage-data') => Dashboard::getUrl(panel: 'admin')] as $target => $expected) {
            auth()->logout();
            session()->put('url.intended', $target);
            Livewire::test(Login::class)->fillForm(['email' => $this->manager->email, 'password' => 'password'])->call('authenticate')->assertRedirect($expected);
            $this->assertFalse(session()->has('url.intended'));
        }
    }

    public function test_old_routes_and_unknown_internal_route_are_denied(): void
    {
        foreach ([null, $this->manager] as $actor) {
            if ($actor) {
                $this->actingAs($actor);
            }
            foreach (['/pak', '/pak/login', '/pak/employees', '/pak/pak-cases/1'] as $path) {
                $this->get($path)->assertNotFound();
            }
        }
        $this->loginRole('Super Admin');
        $panel = Filament::getPanel('admin');
        Route::middleware([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()])->get('/admin/unclassified', fn () => 'must not appear')->name('filament.admin.unclassified');
        $this->get('/admin/unclassified')->assertForbidden();
        $this->assertSame(['admin'], array_keys(Filament::getPanels()));
    }

    public function test_component_context_is_stable_across_two_workspaces(): void
    {
        $this->loginRole('Super Admin');
        $seen = [];
        Livewire::listen('render', function ($component) use (&$seen) {
            if (in_array($component::class, [ManageData::class, ListEmployees::class], true)) {
                $seen[] = [$component::class, app(InternalModuleNavigation::class)->currentModule()];
            }
        });
        $sdm = Livewire::test(ManageData::class);
        $pak = Livewire::test(ListEmployees::class);
        $sdm->call('$refresh')->assertOk();
        $pak->call('$refresh')->assertOk();
        $this->assertSame([[ManageData::class, 'sdm'], [ListEmployees::class, 'pak'], [ManageData::class, 'sdm'], [ListEmployees::class, 'pak']], $seen);
        $this->assertFalse(session()->has('active_module'));
    }

    public function test_module_permission_revocation_blocks_real_livewire_request(): void
    {
        $user = User::factory()->create()->givePermissionTo(['period.view', 'pak.access']);
        $this->actingAs($user);
        $response = $this->get('/admin/manage-data')->assertOk();
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $snapshot = collect($matches[1])->map(fn ($raw) => html_entity_decode($raw, ENT_QUOTES))->first(fn ($raw) => str_contains(json_decode($raw, true)['memo']['name'], 'manage-data'));
        $this->assertNotNull($snapshot);
        // A PAK tab does not change the SDM component's authorization context.
        $this->get('/admin/pak')->assertOk();
        $this->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]], ['X-Livewire' => 'true'])->assertOk();
        $user->revokePermissionTo('period.view');
        $this->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]], ['X-Livewire' => 'true'])->assertForbidden();
        $this->get('/admin/pak')->assertOk();
    }

    public function test_seeder_grants_super_admin_only_and_preserves_account_metadata(): void
    {
        $super = $this->loginRole('Super Admin');
        $super->forceFill(['is_active' => false, 'person_id' => $this->person->id])->save();
        Permission::findOrCreate('pak.test-synthetic', 'web');
        Role::findByName('Super Admin')->revokePermissionTo('pak.access');
        Role::findByName('Admin PAK')->revokePermissionTo('pak.master.manage');
        $before = User::count();
        $this->seed();
        $this->seed();
        $this->assertSame($before, User::count());
        $this->assertFalse($super->fresh()->is_active);
        $this->assertSame($this->person->id, $super->fresh()->person_id);
        foreach (Permission::where('name', 'like', 'pak.%')->pluck('name') as $name) {
            $this->assertTrue(Role::findByName('Super Admin')->hasPermissionTo($name));
        }
        foreach (['Admin SDM', 'Internal Viewer', 'Pegawai'] as $name) {
            $this->assertFalse(Role::findByName($name)->hasPermissionTo('pak.access'));
        }
        $this->assertFalse(Role::findByName('Admin PAK')->hasPermissionTo('pak.master.manage'));
        $this->assertSame(['Super Admin'], $super->fresh()->getRoleNames()->all());
    }
}
