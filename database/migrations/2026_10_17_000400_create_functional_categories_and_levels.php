<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('functional_categories', function (Blueprint $t) {
            $t->id();

            $t->string('code', 100)->unique();
            $t->string('name');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('functional_levels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('functional_category_id')->constrained('functional_categories')->restrictOnDelete();
            $t->string('code', 100)->unique();
            $t->string('name');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('functional_levels');
        Schema::dropIfExists('functional_categories');
    }
};
