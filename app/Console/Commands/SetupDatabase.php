<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SetupDatabase extends Command
{
    protected $signature = 'sdm:setup';

    protected $description = 'Membuat database MySQL jika belum ada, menjalankan migrasi dan membuat role';

    public function handle(): int
    {
        if (config('database.default') === 'mysql') {
            $db = config('database.connections.mysql');
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $db['database'])) {
                $this->error('Nama database hanya boleh huruf, angka, underscore.');

                return self::FAILURE;
            }
            try {
                $pdo = new \PDO('mysql:host='.$db['host'].';port='.$db['port'].';charset=utf8mb4', $db['username'], $db['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$db['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            } catch (\PDOException) {
                $this->error('MySQL belum dapat diakses. Aktifkan server dan periksa DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD di .env.');

                return self::FAILURE;
            }
        }
        if ($this->call('migrate', ['--force' => true]) !== 0) {
            return self::FAILURE;
        }
        if ($this->call('db:seed', ['--force' => true]) !== 0) {
            return self::FAILURE;
        }
        $this->info('Database siap. Buat akun menggunakan php artisan sdm:admin.');

        return self::SUCCESS;
    }
}
