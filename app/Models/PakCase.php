<?php

namespace App\Models;

class PakCase extends PakModel
{
    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'employee_data_as_of' => 'date', 'finalized_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function ruleSet()
    {
        return $this->belongsTo(PakRuleSet::class, 'pak_rule_set_id');
    }

    public function employeeSnapshot()
    {
        return $this->hasOne(PakCaseEmployeeSnapshot::class);
    }

    public function events()
    {
        return $this->hasMany(PakEvent::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
