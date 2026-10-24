<?php

namespace Tests\Feature\Pak;

use App\Models\FunctionalLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_schema_and_seeds_preserve_explicit_access(): void
    {
        $this->seed();
        $this->assertTrue(Schema::hasColumn('pak_cases', 'purpose_type'));
        $this->assertFalse(Schema::hasColumn('pak_cases', 'case_type'));
        $this->assertTrue(Schema::hasTable('position_datasets'));
        $this->assertSame(8, FunctionalLevel::count());
        $user = User::factory()->create()->assignRole('Super Admin');
        $this->assertTrue($user->fresh()->is_active);
        $this->assertTrue($user->can('pak.access'));
        $role = Role::findByName('Super Admin');
        $role->revokePermissionTo('pak.access');
        Role::findByName('Admin PAK')->revokePermissionTo('pak.master.manage');
        $this->seed();
        $this->assertTrue($role->fresh()->hasPermissionTo('pak.access'));
        $this->assertFalse(Role::findByName('Admin PAK')->hasPermissionTo('pak.master.manage'));
        $this->assertSame(8, FunctionalLevel::count());
    }

    public function test_upgrade_preserves_legacy_data_and_defaults_users_active(): void
    {
        $paths = glob(database_path('migrations/2026_10_17_*.php'));
        sort($paths);
        $sourcePaths = glob(database_path('migrations/2026_10_23_*.php'));
        sort($sourcePaths);
        foreach (array_reverse($sourcePaths) as $path) {
            (require $path)->down();
        }
        foreach (array_reverse($paths) as $path) {
            (require $path)->down();
        }
        $db = DB::class;
        $userId = $db::table('users')->insertGetId(['name' => 'Legacy', 'email' => 'legacy@example.test', 'password' => bcrypt('synthetic')]);
        $personId = $db::table('people')->insertGetId(['person_key' => hash('sha256', 'legacy'), 'canonical_name' => 'Legacy Person', 'normalized_name' => 'LEGACY PERSON']);
        $periodId = $db::table('reporting_periods')->insertGetId(['period_month' => '2025-01-01', 'status' => 'published', 'revision' => 1]);
        foreach ($paths as $path) {
            (require $path)->up();
        }
        foreach ($sourcePaths as $path) {
            (require $path)->up();
        }
        $user = User::findOrFail($userId);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->person_id);
        $this->assertDatabaseHas('people', ['id' => $personId, 'canonical_name' => 'Legacy Person']);
        $this->assertDatabaseHas('reporting_periods', ['id' => $periodId, 'status' => 'published', 'revision' => 1]);
        $this->assertDatabaseCount('pak_cases', 0);
    }
}
