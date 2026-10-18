<?php

namespace App\Models;

class FunctionalPosition extends PakModel
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function mappings()
    {
        return $this->hasMany(FunctionalPositionLevel::class);
    }

    public function levels()
    {
        return $this->belongsToMany(FunctionalLevel::class, 'functional_position_levels')->withPivot('is_active')->withTimestamps();
    }
}
