<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonnelSnapshot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['raw_payload' => 'array', 'extra_data' => 'array'];
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function period()
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    public function batch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
