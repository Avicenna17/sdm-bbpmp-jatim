<?php

namespace App\Models;

class EmployeeProfile extends PakModel
{
    protected function casts(): array
    {
        return ['birth_date' => 'date', 'verified_at' => 'datetime', 'extra_data' => 'array'];
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
