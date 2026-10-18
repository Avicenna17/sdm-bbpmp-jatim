<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pak_rule_sets', function (Blueprint $t) {
            $t->id();

            $t->string('code', 100);
            $t->string('name');
            $t->string('version', 50);
            $t->date('valid_from')->nullable();
            $t->date('valid_until')->nullable();
            $t->timestamp('first_used_at')->nullable();
            $t->text('reference')->nullable();
            $t->string('status', 30)->default('DRAFT');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['code', 'version']);
            $t->index(['code', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pak_rule_sets');
    }
};
