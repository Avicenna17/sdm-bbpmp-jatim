<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PakPermissionSeeder extends Seeder
{
    public const PHASE_TWO_PERMISSIONS = ['pak.skp.view', 'pak.skp.manage', 'pak.skp.provisional', 'pak.conversion.view', 'pak.conversion.calculate'];

    public const PERMISSIONS = ['pak.access', 'pak.employee.view', 'pak.employee.manage', 'pak.master.view', 'pak.master.manage', 'pak.rule-set.view', 'pak.rule-set.manage', 'pak.case.view', 'pak.case.create', 'pak.case.update', 'pak.audit.view', ...self::PHASE_TWO_PERMISSIONS];

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
            $role->givePermissionTo(self::PERMISSIONS);
        }
        $role->givePermissionTo(self::PHASE_TWO_PERMISSIONS);
        Role::findOrCreate('Pegawai', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
