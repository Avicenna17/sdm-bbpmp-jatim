<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pak_case_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pak_case_id')->constrained('pak_cases')->restrictOnDelete();
            $table->string('disk', 50);
            $table->string('object_key', 100);
            $table->string('original_filename', 255);
            $table->string('mime_type', 50);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['disk', 'object_key']);
            $table->index(['pak_case_id', 'created_at', 'id'], 'pce_case_created_id_idx');
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pak_case_evidences');
    }
};
