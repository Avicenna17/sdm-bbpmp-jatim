<?php

namespace Tests\Feature;

use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function markPending(): void
    {
        DB::table('migrations')->whereIn('migration', ['2026_09_29_000100_create_monitoring_tables', '2026_09_29_020000_add_source_location_to_import_issues'])->delete();
    }

    public function test_pending_empty_migration_is_recovered_and_users_are_preserved(): void
    {
        $user = User::factory()->create();
        $this->markPending();
        $this->artisan('sdm:repair-monitoring-migration', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('migrations', ['migration' => '2026_09_29_000100_create_monitoring_tables']);
        $this->assertTrue(Schema::hasIndex('position_projection_values', 'projection_unique'));
        $foreign = Schema::getForeignKeys('position_projection_values');
        $this->assertSame('position_requirement_snapshots', $foreign[0]['foreign_table']);
        $this->assertSame('cascade', $foreign[0]['on_delete']);
    }

    public function test_recovery_refuses_any_existing_data_before_dropping_tables(): void
    {
        ReportingPeriod::create(['period_month' => '2026-09-01']);
        $this->markPending();
        $this->artisan('sdm:repair-monitoring-migration', ['--apply' => true])->assertFailed();
        $this->assertDatabaseCount('reporting_periods', 1);
        $this->assertTrue(Schema::hasTable('position_projection_values'));
    }

    public function test_successful_migration_is_never_recreated(): void
    {
        ReportingPeriod::create(['period_month' => '2026-09-01']);
        $this->artisan('sdm:repair-monitoring-migration', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('reporting_periods', 1);
    }

    public function test_check_only_does_not_apply_migrations(): void
    {
        $this->markPending();
        $this->artisan('sdm:repair-monitoring-migration')->assertSuccessful();
        $this->assertDatabaseMissing('migrations', ['migration' => '2026_09_29_000100_create_monitoring_tables']);
    }
}
