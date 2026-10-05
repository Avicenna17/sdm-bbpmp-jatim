<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_templates', function (Blueprint $table) {
            $table->id();
            $table->string('source')->unique();
            $table->string('name');
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->timestamps();
        });
        Schema::create('import_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('definition');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['import_template_id', 'number'], 'template_version_number');
        });
        foreach (['template.view', 'template.manage'] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach (['Super Admin', 'Admin SDM'] as $role) {
                Role::where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
            }
            if ($name === 'template.view') {
                Role::where('name', 'Internal Viewer')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::table('import_batches', fn (Blueprint $t) => $t->foreignId('template_version_id')->nullable()->constrained('import_template_versions'));
        foreach (['personnel_snapshots', 'position_requirement_snapshots'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->json('extra_data')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['personnel_snapshots', 'position_requirement_snapshots'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn('extra_data'));
        }
        Schema::table('import_batches', fn (Blueprint $t) => $t->dropConstrainedForeignId('template_version_id'));
        Schema::dropIfExists('import_template_versions');
        Schema::dropIfExists('import_templates');
    }
};
