<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pak_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pak_case_id')->nullable()->constrained('pak_cases')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('subject_type', 100);
            $t->unsignedBigInteger('subject_id');
            $t->string('event_code', 100);
            $t->json('payload')->nullable();
            $t->timestamp('occurred_at')->useCurrent();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['pak_case_id', 'occurred_at']);
            $t->index(['subject_type', 'subject_id', 'occurred_at'], 'pak_event_subject_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pak_events');
    }
};
