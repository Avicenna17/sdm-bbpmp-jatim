<?php

namespace App\Policies\Pak;

class EmployeeRankHistoryPolicy extends FoundationPolicy
{
    protected string $viewPermission = 'pak.employee.view';
}
