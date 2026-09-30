<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('position_requirement_snapshots', fn (Blueprint $table) => $table->unsignedInteger('source_sequence')->nullable());
    }

    public function down(): void
    {
        Schema::table('position_requirement_snapshots', fn (Blueprint $table) => $table->dropColumn('source_sequence'));
    }
};
