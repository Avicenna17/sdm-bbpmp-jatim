<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_rank_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('rank_name', 150);
            $t->string('grade_code', 30);
            $t->date('tmt_start');
            $t->date('tmt_end')->nullable();
            $t->string('sk_number', 150)->nullable();
            $t->date('sk_date')->nullable();
            $t->string('source', 50);
            $t->string('source_ref');
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('supersedes_id')->nullable()->constrained('employee_rank_histories')->restrictOnDelete();
            $t->timestamp('verified_at');
            $t->timestamp('superseded_at')->nullable();
            $t->timestamps();
            $t->index(['person_id', 'tmt_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_rank_histories');
    }
};
