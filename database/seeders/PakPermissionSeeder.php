<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PakPermissionSeeder extends Seeder
{
    public const PERMISSIONS = ['pak.access', 'pak.employee.view', 'pak.employee.manage', 'pak.master.view', 'pak.master.manage', 'pak.rule-set.view', 'pak.rule-set.manage', 'pak.case.view', 'pak.case.create', 'pak.case.update', 'pak.audit.view'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::findOrCreate('Super Admin', 'web')->givePermissionTo(
            Permission::where('guard_name', 'web')->where('name', 'like', 'pak.%')->get()
        );
        $role = Role::firstOrCreate(['name' => 'Admin PAK', 'guard_name' => 'web']);
        if ($role->wasRecentlyCreated) {
            $role->syncPermissions(self::PERMISSIONS);
        }
        Role::findOrCreate('Pegawai', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
