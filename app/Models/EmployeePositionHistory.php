<?php

namespace App\Models;

use Illuminate\Validation\ValidationException;

class EmployeePositionHistory extends PakModel
{
    protected function casts(): array
    {
        return ['tmt_start' => 'date', 'tmt_end' => 'date', 'sk_date' => 'date', 'verified_at' => 'datetime', 'superseded_at' => 'datetime'];
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function supersedes()
    {
        return $this->belongsTo(EmployeePositionHistory::class, 'supersedes_id');
    }

    public function functionalPosition()
    {
        return $this->belongsTo(FunctionalPosition::class);
    }

    public function functionalLevel()
    {
        return $this->belongsTo(FunctionalLevel::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function ($row) {
            if (array_diff(array_keys($row->getDirty()), ['superseded_at', 'updated_at'])) {
                throw ValidationException::withMessages(['history' => 'Gunakan koreksi versioned.']);
            }
        });
    }
}
