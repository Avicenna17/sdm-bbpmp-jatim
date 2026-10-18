<?php

namespace App\Models;

use Illuminate\Validation\ValidationException;

class PakEvent extends PakModel
{
    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }

    public function pakCase()
    {
        return $this->belongsTo(PakCase::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    const UPDATED_AT = null;

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw ValidationException::withMessages(['event' => 'Audit immutable.']));
    }
}
