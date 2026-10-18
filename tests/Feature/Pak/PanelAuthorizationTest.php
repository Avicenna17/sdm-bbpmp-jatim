<?php

namespace Tests\Feature\Pak;

use App\Domain\Users\AccountStatusService;
use App\Filament\Auth\Login;
use App\Filament\Pak\Resources\EmployeeResource;
use App\Filament\Pak\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Pak\Resources\FunctionalPositionResource\Pages\ManageFunctionalPosition;
use App\Filament\Pak\Resources\PakCaseResource\Pages\CreateCase;
use App\Filament\Pak\Resources\PakCaseResource\Pages\EditCase;
use App\Filament\Pak\Resources\PakRuleSetResource\Pages\ManageRuleSets;
use App\Filament\Resources\UserResource;
use App\Models\FunctionalPosition;
use App\Models\PakCase;
use App\Models\PakEvent;
use App\Models\Person;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

class PanelAuthorizationTest extends PakTestCase
{
    protected function pakLogin(): void
    {
        $this->actingAs($this->manager);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_panel_boundary_and_resource_discovery(): void
    {
        $this->get('/pak/login')->assertNotFound();
        $this->get('/admin')->assertRedirect('/admin/login');
        $sdm = User::factory()->create()->assignRole('Admin SDM');
        $this->actingAs($sdm)->get('/admin/pak')->assertForbidden();
        $this->get('/admin/manage-data')->assertOk();
        $super = User::factory()->create()->assignRole('Super Admin');
        $this->actingAs($super)->get('/admin/pak')->assertOk();
        $this->pakLogin();
        $this->get('/admin/pak')->assertOk();
        $this->get('/admin/manage-data')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/exports/personnel/csv')->assertForbidden();
        $this->get('/templates/personnel')->assertForbidden();
        $this->assertFalse($this->manager->canAccessPanel(Panel::make()->id('unknown')));
        $this->assertSame(['admin'], array_keys(Filament::getPanels()));
        $resources = Filament::getPanel('admin')->getResources();
        $this->assertContains(UserResource::class, $resources);
        $this->assertContains(EmployeeResource::class, $resources);
        $this->assertNull(Gate::getPolicyFor(Person::class));
    }

    public function test_foundation_pages_render_and_audit_is_read_only(): void
    {
        $this->pakLogin();
        foreach (['employees', 'functional-categories', 'functional-levels', 'functional-positions', 'functional-position-levels', 'rule-sets', 'cases', 'audit'] as $slug) {
            $this->get('/admin/pak/'.$slug)->assertOk();
        }
        $this->assertFalse(Gate::forUser($this->manager)->allows('create', PakEvent::class));
        $this->assertFalse(Gate::forUser($this->manager)->allows('deleteAny', PakCase::class));
    }

    public function test_only_access_permission_cannot_read_employee_or_case(): void
    {
        $user = User::factory()->create()->givePermissionTo('pak.access');
        $this->actingAs($user)->get('/admin/pak')->assertOk();
        $this->get('/admin/pak/employees')->assertForbidden();
        $this->get('/admin/pak/cases')->assertForbidden();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListEmployees::class)->assertForbidden();
    }

    public function test_master_and_rule_modal_actions_use_services(): void
    {
        $this->pakLogin();
        Livewire::test(ManageFunctionalPosition::class)->callAction('create', ['code' => 'JF-TEST', 'name' => 'JF Sintetis', 'is_active' => true])->assertHasNoActionErrors();
        $this->assertDatabaseHas('functional_positions', ['code' => 'JF-TEST']);
        $jf = FunctionalPosition::where('code', 'JF-TEST')->firstOrFail();
        Livewire::test(ManageFunctionalPosition::class)->callTableAction('edit', $jf, ['code' => 'JF-TEST', 'name' => 'Nama revisi', 'is_active' => false])->assertHasNoTableActionErrors();
        $this->assertFalse($jf->fresh()->is_active);
        Livewire::test(ManageRuleSets::class)->callAction('create', ['code' => 'RULE', 'name' => 'Metadata', 'version' => '1', 'valid_from' => null, 'valid_until' => null, 'reference' => null])->assertHasNoActionErrors();
        $this->assertDatabaseHas('pak_rule_sets', ['code' => 'RULE', 'valid_from' => null]);
    }

    public function test_profile_and_verified_history_actions(): void
    {
        $this->pakLogin();
        Livewire::test(ListEmployees::class)->callTableAction('profile', $this->person, ['karpeg_number' => 'TEST', 'source_reference' => 'Verifikasi'])->assertHasNoTableActionErrors();
        $this->assertDatabaseHas('employee_profiles', ['person_id' => $this->person->id]);
        Livewire::test(ListEmployees::class)->callTableAction('rankHistory', $this->person, ['rank_name' => 'Penata', 'grade_code' => 'III/c', 'tmt_start' => '2020-01-01', 'source_ref' => 'SK nyata', 'reference_as_of' => '2026-10-01'])->assertHasNoTableActionErrors();
        $this->assertSame('2020-01-01', $this->person->rankHistories()->firstOrFail()->tmt_start->format('Y-m-d'));
    }

    public function test_case_create_view_and_refresh_requires_preview(): void
    {
        $this->pakLogin();
        Livewire::test(CreateCase::class)->fillForm($this->caseInput())->call('create')->assertHasNoFormErrors();
        $case = PakCase::firstOrFail();
        $this->get('/admin/pak/cases/'.$case->id)->assertOk()->assertSee('Pegawai Sintetis');
        $edit = Livewire::test(EditCase::class, ['record' => $case->id])->fillForm(['reason' => 'Tinjauan data']);
        $edit->call('save')->assertHasErrors(['snapshot']);
        $edit->callAction('preview')->assertHasNoActionErrors()->call('save')->assertHasNoFormErrors();
        $this->assertSame(1, $case->fresh()->lock_version);
    }

    public function test_inactive_existing_session_and_new_login_are_denied(): void
    {
        $admin = User::factory()->create()->assignRole('Super Admin');
        $this->pakLogin();
        app(AccountStatusService::class)->change($admin, $this->manager, false, 'Nonaktif');
        $this->get('/admin/pak')->assertForbidden();
        $this->assertGuest();
        $this->actingAs($this->manager)->get('/exports/personnel/csv')->assertForbidden();
        $this->assertGuest();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(Login::class)->fillForm(['email' => $this->manager->email, 'password' => 'password'])->call('authenticate')->assertHasFormErrors(['email']);
    }

    public function test_deactivation_blocks_a_mounted_livewire_mutation_and_template_request(): void
    {
        $this->pakLogin();
        $page = Livewire::test(ManageFunctionalPosition::class)->mountAction('create')->setActionData(['code' => 'DENIED', 'name' => 'Tidak tersimpan', 'is_active' => true]);
        $admin = User::factory()->create()->assignRole('Super Admin');
        app(AccountStatusService::class)->change($admin, $this->manager, false, 'Nonaktif setelah membuka form');
        $page->call('callMountedAction')->assertForbidden();
        $this->assertDatabaseMissing('functional_positions', ['code' => 'DENIED']);
        $this->actingAs($this->manager)->get('/templates/personnel')->assertForbidden();
        $this->assertGuest();
    }
}
