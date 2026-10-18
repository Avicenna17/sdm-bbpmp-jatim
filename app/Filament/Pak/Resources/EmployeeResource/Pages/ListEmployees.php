<?php

namespace App\Filament\Pak\Resources\EmployeeResource\Pages;

use App\Filament\Pak\Resources\EmployeeResource;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;
}
