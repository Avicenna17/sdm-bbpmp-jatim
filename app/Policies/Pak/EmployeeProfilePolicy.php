<?php

namespace App\Policies\Pak;

class EmployeeProfilePolicy extends FoundationPolicy
{
    protected string $viewPermission = 'pak.employee.view';

    protected ?string $managePermission = 'pak.employee.manage';
}
