<?php

namespace App\Models;

class PakCaseEmployeeSnapshot extends PakModel
{
    protected function casts(): array
    {
        return ['birth_date' => 'date', 'rank_tmt' => 'date', 'position_tmt' => 'date', 'captured_at' => 'datetime', 'snapshot_payload' => 'array'];
    }

    public function pakCase()
    {
        return $this->belongsTo(PakCase::class);
    }

    public function capturer()
    {
        return $this->belongsTo(User::class, 'captured_by');
    }
}
