<?php

namespace Tests\Feature\Pak;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PakPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SkpPermissionBaselineTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = ['pak.skp.view', 'pak.skp.manage', 'pak.skp.provisional', 'pak.conversion.view', 'pak.conversion.calculate'];

    public function test_fresh_baseline_has_exact_permissions_and_approved_role_mapping(): void
    {
        $this->seed(DatabaseSeeder::class);
        foreach (self::EXPECTED as $name) {
            $this->assertSame(1, Permission::where('name', $name)->where('guard_name', 'web')->count());
            foreach (['Super Admin', 'Admin PAK'] as $role) {
                $this->assertTrue(Role::findByName($role)->hasPermissionTo($name));
            }
            foreach (['Admin SDM', 'Pegawai', 'Internal Viewer'] as $role) {
                $this->assertFalse(Role::findByName($role)->hasPermissionTo($name));
            }
        }
        $this->assertDatabaseCount('performance_evaluations', 0);
        $this->assertDatabaseCount('pak_skp_import_batches', 0);
    }

    public function test_existing_roles_and_custom_grants_survive_both_seed_entrypoints(): void
    {
        $custom = Permission::findOrCreate('custom.report', 'web');
        $pakCustom = Permission::findOrCreate('pak.custom.report', 'web');
        foreach (['Super Admin', 'Admin PAK', 'Admin SDM', 'Pegawai', 'Custom Role'] as $name) {
            Role::findOrCreate($name, 'web')->givePermissionTo([$custom, $pakCustom]);
        }
        $user = User::factory()->create(['is_active' => false])->assignRole('Admin PAK');
        $userBefore = $user->fresh()->getAttributes();
        $assignments = DB::table('model_has_roles')->orderBy('role_id')->orderBy('model_type')->orderBy('model_id')->get()->toArray();
        $this->seed(PakPermissionSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $permissionCount = Permission::count();
        $grantCount = DB::table('role_has_permissions')->count();
        $roleCount = Role::count();
        $this->seed(PakPermissionSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($permissionCount, Permission::count());
        $this->assertSame($grantCount, DB::table('role_has_permissions')->count());
        $this->assertSame($roleCount, Role::count());
        $this->assertSame($userBefore, $user->fresh()->getAttributes());
        $this->assertEquals($assignments, DB::table('model_has_roles')->orderBy('role_id')->orderBy('model_type')->orderBy('model_id')->get()->toArray());
        foreach (['Super Admin', 'Admin PAK', 'Admin SDM', 'Pegawai', 'Custom Role'] as $name) {
            $role = Role::findByName($name);
            $this->assertTrue($role->hasPermissionTo($custom));
            $this->assertTrue($role->hasPermissionTo($pakCustom));
            foreach (self::EXPECTED as $permission) {
                $this->assertSame(in_array($name, ['Super Admin', 'Admin PAK'], true), $role->hasPermissionTo($permission));
            }
        }
    }
}
