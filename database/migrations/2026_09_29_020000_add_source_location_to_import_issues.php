<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_issues', function (Blueprint $table) {
            $table->string('source_sheet')->nullable();
            $table->string('source_cell', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_issues', function (Blueprint $table) {
            $table->dropColumn(['source_sheet', 'source_cell']);
        });
    }
};
