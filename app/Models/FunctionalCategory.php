<?php

namespace App\Models;

class FunctionalCategory extends PakModel
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function levels()
    {
        return $this->hasMany(FunctionalLevel::class);
    }
}
