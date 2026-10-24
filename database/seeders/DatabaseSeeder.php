<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['period.view', 'period.create', 'period.publish', 'period.revise', 'import.view', 'import.create', 'import.commit', 'personnel.view', 'position_requirement.view', 'export.personnel', 'export.position_requirement', 'user.manage', 'role.manage', 'template.view', 'template.manage'];
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->ensureSdmPermissions('Super Admin', $permissions);
        $this->ensureSdmPermissions('Admin SDM', array_diff($permissions, ['user.manage', 'role.manage', 'period.revise']));
        $this->ensureSdmPermissions('Internal Viewer', ['template.view', 'period.view', 'personnel.view', 'position_requirement.view', 'export.personnel', 'export.position_requirement']);
        $this->call([PakPermissionSeeder::class, FunctionalMasterSeeder::class]);
    }

    private function ensureSdmPermissions(string $name, array $permissions): void
    {
        $role = Role::findOrCreate($name, 'web');
        // Reseeding must preserve explicit grants, including non-PAK custom permissions.
        $role->givePermissionTo($permissions);
    }
}
