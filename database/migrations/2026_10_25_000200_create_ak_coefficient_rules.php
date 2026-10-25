<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ak_coefficient_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pak_rule_set_id')->constrained('pak_rule_sets')->restrictOnDelete();
            $t->foreignId('functional_level_id')->constrained('functional_levels')->restrictOnDelete();
            $t->decimal('annual_coefficient', 15, 6);
            $t->timestamps();
            $t->unique(['pak_rule_set_id', 'functional_level_id'], 'acr_rule_level_uq');
            $t->index('functional_level_id', 'acr_level_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ak_coefficient_rules');
    }
};
