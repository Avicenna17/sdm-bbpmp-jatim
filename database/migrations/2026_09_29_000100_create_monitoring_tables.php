<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_periods', function (Blueprint $t) {
            $t->id();
            $t->date('period_month')->unique();
            $t->string('status', 20)->default('draft');
            $t->timestamp('published_at')->nullable();
            $t->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('revision')->default(0);
            $t->timestamps();
        });
        Schema::create('people', function (Blueprint $t) {
            $t->id();
            $t->char('person_key', 64)->unique();
            $t->string('nip', 30)->nullable()->unique();
            $t->string('canonical_name');
            $t->string('normalized_name')->index();
            $t->timestamps();
        });
        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reporting_period_id')->constrained()->restrictOnDelete();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('source_type', 40);
            $t->string('original_filename');
            $t->char('sha256', 64)->index();
            $t->string('disk', 50)->default('local');
            $t->string('path', 500);
            $t->string('status', 30)->default('uploaded');
            foreach (['total_rows', 'valid_rows', 'warning_rows', 'error_rows', 'base_revision'] as $field) {
                $t->unsignedInteger($field)->default(0);
            }
            $t->json('summary')->nullable();
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
            $t->index(['reporting_period_id', 'source_type', 'status'], 'batches_period_source_status');
        });
        Schema::create('import_issues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('source_row')->nullable();
            $t->string('severity', 20);
            $t->string('field_name', 100)->nullable();
            $t->string('code', 100);
            $t->text('message');
            $t->json('row_payload')->nullable();
            $t->timestamps();
        });
        Schema::create('personnel_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reporting_period_id')->constrained()->cascadeOnDelete();
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('source_row_no')->nullable();
            $t->unsignedInteger('source_sequence')->nullable();
            $t->string('name_at_period');
            $t->string('nip_at_period', 30)->nullable();
            $t->string('employment_group', 20);
            $t->string('employment_status', 40);
            foreach (['rank_name', 'grade_code', 'position_name', 'placement_current', 'placement_initial', 'assignment_detail', 'education_level', 'education_raw'] as $field) {
                $t->string($field)->nullable();
            }
            $t->unsignedTinyInteger('position_class')->nullable();
            $t->char('gender', 1)->nullable();
            $t->json('raw_payload')->nullable();
            $t->timestamps();
            $t->unique(['reporting_period_id', 'person_id']);
            foreach (['employment_group', 'employment_status', 'gender', 'education_level'] as $field) {
                $t->index(['reporting_period_id', $field], 'personnel_period_'.$field);
            }
        });
        Schema::create('position_requirement_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reporting_period_id')->constrained()->cascadeOnDelete();
            $t->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('source_row_no')->nullable();
            $t->char('position_key', 64);
            $t->string('position_name');
            foreach (['parent_org', 'work_unit', 'position_type', 'requirement_status'] as $field) {
                $t->string($field)->nullable();
            }
            $t->unsignedTinyInteger('position_class')->nullable();
            $t->unsignedSmallInteger('retirement_age')->nullable();
            foreach (['incumbent_count', 'requirement_count', 'retirement_5y_total'] as $field) {
                $t->unsignedInteger($field)->nullable();
            }
            $t->integer('vacancy_count')->nullable();
            $t->json('raw_payload')->nullable();
            $t->timestamps();
            $t->unique(['reporting_period_id', 'position_key'], 'positions_period_key');
        });
        Schema::create('position_projection_values', function (Blueprint $t) {
            $t->id();
            $t->foreignId('position_requirement_snapshot_id')
                ->constrained('position_requirement_snapshots', indexName: 'projection_snapshot_fk')
                ->cascadeOnDelete();
            $t->string('metric_type', 40);
            $t->unsignedSmallInteger('projection_year');
            $t->integer('value')->nullable();
            $t->timestamps();
            $t->unique(['position_requirement_snapshot_id', 'metric_type', 'projection_year'], 'projection_unique');
        });
    }

    public function down(): void
    {
        foreach (['position_projection_values', 'position_requirement_snapshots', 'personnel_snapshots', 'import_issues', 'import_batches', 'people', 'reporting_periods'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
