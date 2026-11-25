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
            $table->timestamp('published_at')->nullable()->index();
        });
        // Recover only versions whose remaining snapshots were actually public.
        $legacy = DB::table('import_batches')
            ->join(
                'reporting_periods',
                'reporting_periods.id',
                '=',
                'import_batches.reporting_period_id'
            )
            ->where('import_batches.source_type', 'POSITION_REQUIREMENT')
            ->where('import_batches.status', 'committed')
            ->where('reporting_periods.status', 'published')
            ->whereExists(
                fn($q) => $q->selectRaw('1')
                    ->from('position_requirement_snapshots')
                    ->whereColumn(
                        'position_requirement_snapshots.import_batch_id',
                        'import_batches.id'
                    )
            )
            ->get([
                'import_batches.id',
                'import_batches.committed_at',
                'reporting_periods.published_at',
            ]);
        foreach ($legacy as $batch) {
            DB::table('import_batches')->where('id', $batch->id)->update(['published_at' => $batch->published_at ?? $batch->committed_at ?? now()]);
        }
        $state = DB::table('position_datasets')->where('id', 1)->first();
        if ($state?->published_batch_id) {
            DB::table('import_batches')->where('id', $state->published_batch_id)->update(['published_at' => $state->published_at ?? now()]);
        }
    }

    public function down(): void
    {
        Schema::table('import_batches', fn(Blueprint $table) => $table->dropColumn('published_at'));
    }
};
