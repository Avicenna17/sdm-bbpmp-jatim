<?php

namespace App\Policies\Pak;

class EmployeePositionHistoryPolicy extends FoundationPolicy
{
    protected string $viewPermission = 'pak.employee.view';
}
