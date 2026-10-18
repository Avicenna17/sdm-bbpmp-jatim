<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

abstract class PakModel extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw ValidationException::withMessages(['record' => 'Data fondasi dipertahankan. Gunakan koreksi atau nonaktifkan master.']));
    }
}
