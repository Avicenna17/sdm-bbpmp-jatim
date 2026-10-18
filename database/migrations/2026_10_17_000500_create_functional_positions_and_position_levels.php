<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('functional_positions', function (Blueprint $t) {
            $t->id();

            $t->string('code', 100)->nullable()->unique();
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('functional_position_levels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('functional_position_id')->constrained('functional_positions')->restrictOnDelete();
            $t->foreignId('functional_level_id')->constrained('functional_levels')->restrictOnDelete();
            $t->boolean('is_active')->default(true);
            $t->unique(['functional_position_id', 'functional_level_id'], 'jf_position_level_unique');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('functional_position_levels');
        Schema::dropIfExists('functional_positions');
    }
};
