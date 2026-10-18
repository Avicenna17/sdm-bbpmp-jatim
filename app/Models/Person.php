<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function employeeProfile()
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function rankHistories()
    {
        return $this->hasMany(EmployeeRankHistory::class);
    }

    public function positionHistories()
    {
        return $this->hasMany(EmployeePositionHistory::class);
    }

    public function pakCases()
    {
        return $this->hasMany(PakCase::class);
    }

    public function snapshots()
    {
        return $this->hasMany(PersonnelSnapshot::class);
    }
}
