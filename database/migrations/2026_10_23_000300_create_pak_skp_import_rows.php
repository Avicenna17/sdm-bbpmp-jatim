<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pak_skp_import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('pak_skp_import_batches')->restrictOnDelete();
            $t->unsignedInteger('record_ordinal');
            $t->string('source_sheet')->nullable();
            $t->unsignedInteger('source_row')->nullable();
            $t->json('source_locator')->nullable();
            $t->foreignId('person_id')->nullable()->constrained('people')->restrictOnDelete();
            $t->string('person_resolution_status', 30)->default('PENDING');
            $t->string('match_method', 50)->nullable();
            $t->string('source_reference')->nullable();
            $t->string('source_record_identifier')->nullable();
            $t->char('source_identity_fingerprint', 64)->nullable();
            $t->char('content_fingerprint', 64)->nullable();
            $t->json('normalized_payload')->nullable();
            $t->json('raw_provenance')->nullable();
            $t->string('processing_status', 30)->default('PENDING');
            $t->json('issue_codes')->nullable();
            $t->json('error_metadata')->nullable();
            $t->foreignId('performance_evaluation_id')->nullable()->constrained('performance_evaluations', indexName: 'psir_evaluation_fk')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['batch_id', 'record_ordinal'], 'psir_batch_ordinal_uq');
            $t->index('person_id', 'psir_person_idx');
            $t->index('performance_evaluation_id', 'psir_evaluation_idx');
            $t->index('source_identity_fingerprint', 'psir_source_identity_idx');
            $t->index('content_fingerprint', 'psir_content_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pak_skp_import_rows');
    }
};
