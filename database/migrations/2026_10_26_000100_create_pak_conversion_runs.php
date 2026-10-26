<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pak_conversion_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pak_case_id')->constrained('pak_cases', indexName: 'pcr_case_fk')->restrictOnDelete();
            $t->foreignId('person_id')->constrained('people', indexName: 'pcr_person_fk')->restrictOnDelete();
            $t->foreignId('pak_rule_set_id')->constrained('pak_rule_sets', indexName: 'pcr_rule_fk')->restrictOnDelete();
            $t->unsignedInteger('revision_number');
            $t->foreignId('predecessor_run_id')->nullable()->constrained('pak_conversion_runs', indexName: 'pcr_predecessor_fk')->restrictOnDelete();
            $t->text('revision_reason')->nullable();
            $t->string('status', 30);
            $t->foreignId('active_case_id')->nullable()->constrained('pak_cases', indexName: 'pcr_active_case_fk')->restrictOnDelete();
            $t->date('requested_start');
            $t->date('requested_end');
            // Post-save authoritative case version N+1; orchestration belongs to 02C.5.
            $t->unsignedInteger('case_lock_version');
            $t->char('dependency_fingerprint', 64);
            $t->char('snapshot_fingerprint', 64);
            $t->unsignedSmallInteger('snapshot_schema_version');
            $t->unsignedSmallInteger('calculation_contract_version');
            $t->string('rounding_profile', 50);
            $t->unsignedSmallInteger('rounding_version');
            $t->json('dependency_manifest');
            $t->json('original_issues');
            $t->json('calculation_trace');
            $t->text('total_numerator')->nullable();
            $t->text('total_denominator')->nullable();
            $t->json('review_assessment')->nullable();
            $t->foreignId('review_assessed_by')->nullable()->constrained('users', indexName: 'pcr_review_actor_fk')->restrictOnDelete();
            $t->timestamp('review_assessed_at')->nullable();
            $t->foreignId('superseded_by_user_id')->nullable()->constrained('users', indexName: 'pcr_superseded_actor_fk')->restrictOnDelete();
            $t->timestamp('superseded_at')->nullable();
            $t->foreignId('created_by')->constrained('users', indexName: 'pcr_creator_fk')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['pak_case_id', 'revision_number'], 'pcr_case_revision_uq');
            $t->unique('predecessor_run_id', 'pcr_predecessor_uq');
            $t->unique('active_case_id', 'pcr_active_case_uq');
            $t->index(['person_id', 'created_at'], 'pcr_person_created_idx');
            $t->index('pak_rule_set_id', 'pcr_rule_idx');
            $t->index('review_assessed_by', 'pcr_review_actor_idx');
            $t->index('superseded_by_user_id', 'pcr_superseded_actor_idx');
            $t->index('created_by', 'pcr_creator_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pak_conversion_runs');
    }
};
