<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pak_case_employee_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pak_case_id')->constrained('pak_cases')->restrictOnDelete();
            $t->unique('pak_case_id');
            $t->string('name')->nullable();
            $t->string('nip')->nullable();
            $t->string('karpeg_number')->nullable();
            $t->string('birth_place')->nullable();
            $t->string('gender')->nullable();
            $t->string('rank_name')->nullable();
            $t->string('grade_code')->nullable();
            $t->string('position_name')->nullable();
            $t->string('position_type')->nullable();
            $t->string('functional_position_code')->nullable();
            $t->string('functional_position_name')->nullable();
            $t->string('functional_level_code')->nullable();
            $t->string('functional_level_name')->nullable();
            $t->string('functional_category_code')->nullable();
            $t->string('functional_category_name')->nullable();
            $t->string('work_unit')->nullable();
            $t->string('institution_name')->nullable();
            $t->date('birth_date')->nullable();
            $t->date('rank_tmt')->nullable();
            $t->date('position_tmt')->nullable();
            $t->foreignId('functional_position_id')->nullable()->constrained('functional_positions')->restrictOnDelete();
            $t->foreignId('functional_level_id')->nullable()->constrained('functional_levels')->restrictOnDelete();
            $t->foreignId('captured_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('captured_at');
            $t->unsignedSmallInteger('schema_version')->default(1);
            $t->json('snapshot_payload');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pak_case_employee_snapshots');
    }
};
