<?php

namespace App\Models;

class FunctionalPositionLevel extends PakModel
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function functionalPosition()
    {
        return $this->belongsTo(FunctionalPosition::class);
    }

    public function functionalLevel()
    {
        return $this->belongsTo(FunctionalLevel::class);
    }
}
