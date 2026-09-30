<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepairMonitoringMigration extends Command
{
    protected $signature = 'sdm:repair-monitoring-migration {--apply : Buat ulang tabel monitoring kosong dan jalankan migrasi}';

    protected $description = 'Memulihkan migrasi monitoring yang terhenti tanpa menghapus data pengguna';

    private const MIGRATION = '2026_09_29_000100_create_monitoring_tables';

    private const TABLES = [
        'position_projection_values', 'position_requirement_snapshots',
        'personnel_snapshots', 'import_issues', 'import_batches', 'people', 'reporting_periods',
    ];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Pemulihan otomatis ini hanya untuk instalasi awal non-produksi.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('migrations')) {
            $this->error('Belum ada tabel migrations. Jalankan php artisan migrate.');

            return self::FAILURE;
        }

        if (DB::table('migrations')->where('migration', self::MIGRATION)->exists()) {
            $this->info('Migrasi monitoring sudah berhasil. Tidak ada tabel yang diubah.');

            return self::SUCCESS;
        }

        // Check every table before any DDL: never erase an existing snapshot or audit.
        $tables = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (DB::table($table)->exists()) {
                $this->error("Pemulihan dibatalkan: {$table} berisi data. Tidak ada tabel yang diubah.");

                return self::FAILURE;
            }
            $tables[] = $table;
        }

        $this->info('Tabel kosong dari migrasi yang belum selesai: '.implode(', ', $tables));
        if (! $this->option('apply')) {
            $this->info('Pemeriksaan saja. Gunakan --apply untuk membuat ulang tabel kosong tersebut.');

            return self::SUCCESS;
        }

        // Child-first order; foreign-key checks remain enabled.
        foreach ($tables as $table) {
            Schema::drop($table);
        }

        return $this->call('migrate', ['--force' => true]);
    }
}
