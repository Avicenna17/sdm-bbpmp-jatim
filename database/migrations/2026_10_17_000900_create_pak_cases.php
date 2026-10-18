<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pak_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->foreignId('pak_rule_set_id')->nullable()->constrained('pak_rule_sets')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('case_number', 100)->nullable();
            $t->string('source_type', 50)->default('ADMIN_MANUAL');
            $t->string('purpose_type', 50);
            $t->string('status', 50)->default('DRAFT');
            $t->date('period_start');
            $t->date('period_end');
            $t->date('employee_data_as_of');
            $t->unsignedInteger('lock_version')->default(0);
            $t->timestamp('finalized_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['person_id', 'status']);
            $t->index(['status', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pak_cases');
    }
};
