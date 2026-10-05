<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportTemplateVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'activated_at' => 'datetime'];
    }

    public function template()
    {
        return $this->belongsTo(ImportTemplate::class, 'import_template_id');
    }
}
