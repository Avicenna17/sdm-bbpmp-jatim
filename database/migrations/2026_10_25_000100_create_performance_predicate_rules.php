<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_predicate_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pak_rule_set_id')->constrained('pak_rule_sets')->restrictOnDelete();
            $code = $t->string('predicate_code', 50);
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $code->collation('utf8mb4_bin');
            }
            $t->string('predicate_name', 100);
            $t->decimal('predicate_factor', 8, 4);
            $t->timestamps();
            $t->unique(['pak_rule_set_id', 'predicate_code'], 'ppr_rule_code_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_predicate_rules');
    }
};
