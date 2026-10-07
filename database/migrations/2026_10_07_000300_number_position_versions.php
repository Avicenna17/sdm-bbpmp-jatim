<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('position_version')->nullable()->unique();
        });
        $number = 0;
        foreach (DB::table('import_batches')->where('source_type', 'POSITION_REQUIREMENT')->where('status', 'committed')->orderBy('committed_at')->orderBy('id')->get(['id']) as $batch) {
            DB::table('import_batches')->where('id', $batch->id)->update(['position_version' => ++$number]);
        }
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropUnique(['position_version']);
            $table->dropColumn('position_version');
        });
    }
};
