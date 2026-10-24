<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_evaluations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->unsignedSmallInteger('performance_year');
            $t->string('evaluation_type', 30);
            $t->string('source_kind', 30);
            $t->string('source_reference');
            $t->string('source_record_identifier')->nullable();
            $t->string('predicate_code', 50);
            $t->string('predicate_label_original');
            $t->date('coverage_start');
            $t->date('coverage_end');
            $t->date('source_date')->nullable();
            $t->string('source_position_name')->nullable();
            $t->string('source_functional_level_label')->nullable();
            $t->date('source_position_tmt')->nullable();
            $t->string('source_sk_number', 150)->nullable();
            $t->date('source_sk_date')->nullable();
            $t->foreignId('employee_position_history_id')->nullable()->constrained('employee_position_histories', indexName: 'pe_position_history_fk')->restrictOnDelete();
            $t->text('historical_reason')->nullable();
            $t->char('source_identity_fingerprint', 64);
            $t->char('content_fingerprint', 64);
            $t->char('current_source_identity_fingerprint', 64)->nullable();
            $t->string('status', 30)->default('CURRENT');
            $t->foreignId('previous_version_id')->nullable()->constrained('performance_evaluations', indexName: 'pe_previous_version_fk')->restrictOnDelete();
            $t->text('revision_reason')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('superseded_by_user_id')->nullable()->constrained('users', indexName: 'pe_superseded_by_fk')->restrictOnDelete();
            $t->timestamp('superseded_at')->nullable();
            $t->timestamps();
            $t->unique('current_source_identity_fingerprint', 'pe_current_source_uq');
            $t->unique('previous_version_id', 'pe_previous_version_uq');
            $t->index('source_identity_fingerprint', 'pe_source_identity_idx');
            $t->index(['person_id', 'performance_year', 'evaluation_type', 'status'], 'pe_person_year_type_status_idx');
            $t->index(['person_id', 'coverage_start', 'coverage_end'], 'pe_person_coverage_idx');
            $t->index('employee_position_history_id', 'pe_position_history_idx');
            $t->index('created_by', 'pe_created_by_idx');
            $t->index('superseded_by_user_id', 'pe_superseded_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_evaluations');
    }
};
