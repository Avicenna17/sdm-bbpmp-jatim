<?php

namespace App\Models;

class FunctionalLevel extends PakModel
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function category()
    {
        return $this->belongsTo(FunctionalCategory::class, 'functional_category_id');
    }

    public function mappings()
    {
        return $this->hasMany(FunctionalPositionLevel::class);
    }

    public function positions()
    {
        return $this->belongsToMany(FunctionalPosition::class, 'functional_position_levels')->withPivot('is_active')->withTimestamps();
    }
}
