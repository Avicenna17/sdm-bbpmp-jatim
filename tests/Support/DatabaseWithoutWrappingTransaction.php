<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Commit services own their top-level transaction; retain isolated fresh SQLite fixtures. */
trait DatabaseWithoutWrappingTransaction
{
    public function refreshDatabase(): void
    {
        if (DB::connection()->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Non-wrapping feature fixtures require in-memory SQLite.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
    }
}
