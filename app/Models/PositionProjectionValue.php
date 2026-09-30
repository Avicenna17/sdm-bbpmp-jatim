<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PositionProjectionValue extends Model
{
    protected $guarded = ['id'];

    public function snapshot()
    {
        return $this->belongsTo(PositionRequirementSnapshot::class, 'position_requirement_snapshot_id');
    }
}
