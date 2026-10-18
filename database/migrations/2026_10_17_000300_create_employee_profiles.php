<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->unique('person_id');
            $t->string('karpeg_number', 100)->nullable();
            $t->string('birth_place', 150)->nullable();
            $t->date('birth_date')->nullable();
            $t->char('gender', 1)->nullable();
            $t->string('institution_name')->nullable();
            $t->string('work_unit_name')->nullable();
            $t->json('extra_data')->nullable();
            $t->string('source_reference');
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
