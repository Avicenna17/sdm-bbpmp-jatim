<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->unsignedBigInteger('reporting_period_id')->nullable()->change();
            });
            Schema::table('position_requirement_snapshots', function (Blueprint $table) {
                $table->unsignedBigInteger('reporting_period_id')->nullable()->change();
                $table->unique(['import_batch_id', 'position_key'], 'positions_batch_key');
            });
        });
        Schema::create('position_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_batch_id')->nullable()->constrained('import_batches')->restrictOnDelete();
            $table->foreignId('published_batch_id')->nullable()->constrained('import_batches')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        // Keep all legacy snapshots and import paths intact for audit/revalidation.
        $legacy = DB::table('import_batches as b')
            ->join('reporting_periods as p', 'p.id', '=', 'b.reporting_period_id')
            ->where('b.source_type', 'POSITION_REQUIREMENT')->where('b.status', 'committed')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('position_requirement_snapshots as s')->whereColumn('s.import_batch_id', 'b.id'))
            ->orderByDesc('p.period_month')->orderByDesc('b.id');
        $published = (clone $legacy)->where('p.status', 'published')->first(['b.id', 'p.published_at', 'p.published_by']);
        $draft = (clone $legacy)->value('b.id');
        DB::table('position_datasets')->insert(['id' => 1, 'draft_batch_id' => $draft, 'published_batch_id' => $published?->id,
            'revision' => 0, 'published_at' => $published?->published_at, 'published_by' => $published?->published_by,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('reporting_periods')->where('status', '!=', 'published')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('personnel_snapshots')->whereColumn('reporting_period_id', 'reporting_periods.id'))
            ->update(['status' => 'ready']);
    }

    public function down(): void
    {
        // A forward-only data migration: global versions cannot be assigned a fictional month.
        throw new RuntimeException('Restore the pre-deployment database backup to roll back this data migration.');
    }
};
