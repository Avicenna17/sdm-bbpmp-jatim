<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel_snapshots', function (Blueprint $table) {
            $table->string('nip_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personnel_snapshots', fn (Blueprint $table) => $table->dropColumn('nip_note'));
    }
};
