<?php

namespace App\Models;

use Illuminate\Validation\ValidationException;

class PakCaseEvidence extends PakModel
{
    public const UPDATED_AT = null;

    protected $table = 'pak_case_evidences';

    protected $hidden = ['disk', 'object_key', 'checksum_sha256'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw ValidationException::withMessages(['evidence' => 'Dokumen tersimpan tidak dapat diubah.']));
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
