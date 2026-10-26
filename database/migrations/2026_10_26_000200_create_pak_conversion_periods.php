<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pak_conversion_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pak_conversion_run_id')->constrained('pak_conversion_runs', indexName: 'pcp_run_fk')->restrictOnDelete();
            $t->unsignedInteger('line_number');
            $t->date('period_start');
            $t->date('period_end');
            $t->unsignedInteger('month_count');
            $t->foreignId('performance_evaluation_id')->nullable()->constrained('performance_evaluations', indexName: 'pcp_evaluation_fk')->restrictOnDelete();
            $t->index('performance_evaluation_id', 'pcp_evaluation_idx');
            $t->foreignId('source_position_history_id')->nullable()->constrained('employee_position_histories', indexName: 'pcp_source_history_fk')->restrictOnDelete();
            $t->index('source_position_history_id', 'pcp_source_history_idx');
            $t->foreignId('employee_position_history_id')->nullable()->constrained('employee_position_histories', indexName: 'pcp_position_history_fk')->restrictOnDelete();
            $t->index('employee_position_history_id', 'pcp_position_history_idx');
            $t->foreignId('functional_level_id')->nullable()->constrained('functional_levels', indexName: 'pcp_level_fk')->restrictOnDelete();
            $t->index('functional_level_id', 'pcp_level_idx');
            $t->foreignId('performance_predicate_rule_id')->nullable()->constrained('performance_predicate_rules', indexName: 'pcp_predicate_fk')->restrictOnDelete();
            $t->index('performance_predicate_rule_id', 'pcp_predicate_idx');
            $t->foreignId('ak_coefficient_rule_id')->nullable()->constrained('ak_coefficient_rules', indexName: 'pcp_coefficient_fk')->restrictOnDelete();
            $t->index('ak_coefficient_rule_id', 'pcp_coefficient_idx');
            $t->decimal('predicate_factor', 8, 4)->nullable();
            $t->decimal('annual_coefficient', 15, 6)->nullable();
            $t->text('result_numerator')->nullable();
            $t->text('result_denominator')->nullable();
            $t->json('source_snapshot')->nullable();
            $t->json('position_snapshot')->nullable();
            $t->json('rule_snapshot')->nullable();
            $t->json('original_issues');
            $t->json('calculation_trace');
            $t->timestamps();
            $t->unique(['pak_conversion_run_id', 'line_number'], 'pcp_run_line_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pak_conversion_periods');
    }
};
