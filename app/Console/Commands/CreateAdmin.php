<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'sdm:admin {email?} {--name=Administrator SDM}';

    protected $description = 'Membuat Super Admin dengan password yang dimasukkan secara aman';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Email');
        $password = $this->secret('Password (minimal 12 karakter)');
        $v = Validator::make(compact('email', 'password'), ['email' => 'required|email|unique:users,email', 'password' => 'required|string|min:12']);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $this->call('db:seed', ['--force' => true]);
        User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password])->assignRole('Super Admin');
        $this->info('Super Admin berhasil dibuat. Login melalui /admin.');

        return self::SUCCESS;
    }
}
