<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pak_skp_import_batches', function (Blueprint $t) {
            $t->id();
            $t->string('disk', 50);
            $t->string('object_key', 500);
            $t->string('original_filename');
            $t->char('checksum_sha256', 64);
            $t->string('adapter_key', 100);
            $t->string('adapter_version', 50);
            $t->string('schema_version', 50);
            $t->string('status', 30)->default('PARSING');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('committed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('committed_at')->nullable();
            $t->json('summary')->nullable();
            $t->json('error_metadata')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at'], 'psib_status_created_idx');
            $t->index('checksum_sha256', 'psib_checksum_idx');
            $t->index('created_by', 'psib_created_by_idx');
            $t->index('committed_by', 'psib_committed_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pak_skp_import_batches');
    }
};
