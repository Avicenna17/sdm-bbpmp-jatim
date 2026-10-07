<?php

namespace Tests\Feature;

use App\Domain\Import\WorkbookParser;
use App\Models\ImportBatch;
use App\Models\PositionDataset;
use App\Models\PositionRequirementSnapshot;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PositionMigrationTest extends TestCase
{
    public function test_position_migration_preserves_published_and_draft_legacy_versions(): void
    {
        config(['database.connections.migration_test' => config('database.connections.sqlite'), 'database.default' => 'migration_test']);
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $admin = User::factory()->create();
        $published = ReportingPeriod::create(['period_month' => '2026-09-01']);
        $published->update(['status' => 'published', 'published_at' => now(), 'published_by' => $admin->id]);
        $draft = ReportingPeriod::create(['period_month' => '2026-10-01']);
        $ids = [];
        foreach ([$published, $draft] as $period) {
            $batch = ImportBatch::create(['reporting_period_id' => $period->id, 'uploaded_by' => $admin->id,
                'source_type' => WorkbookParser::POSITION, 'original_filename' => 'legacy.xlsx', 'sha256' => str_repeat('a', 64),
                'path' => 'imports/'.$period->id.'/legacy.xlsx', 'status' => 'committed']);
            $ids[] = $batch->id;
            PositionRequirementSnapshot::create(['reporting_period_id' => $period->id, 'import_batch_id' => $batch->id,
                'position_key' => str_repeat('a', 64), 'position_name' => 'Legacy '.$period->id]);
        }
        Schema::drop('position_datasets');
        Schema::table('position_requirement_snapshots', fn ($table) => $table->dropUnique('positions_batch_key'));
        (require database_path('migrations/2026_10_07_000100_decouple_position_dataset.php'))->up();
        $state = PositionDataset::current();
        $this->assertSame($ids[0], $state->published_batch_id);
        $this->assertSame($ids[1], $state->draft_batch_id);
        $this->assertSame(2, PositionRequirementSnapshot::count());
        $this->assertSame('imports/'.$published->id.'/legacy.xlsx', ImportBatch::find($ids[0])->path);
    }
}
