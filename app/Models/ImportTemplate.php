<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportTemplate extends Model
{
    protected $guarded = ['id'];

    public function versions()
    {
        return $this->hasMany(ImportTemplateVersion::class);
    }

    public function activeVersion()
    {
        return $this->belongsTo(ImportTemplateVersion::class, 'active_version_id');
    }
}
