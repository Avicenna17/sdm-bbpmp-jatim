<?php

namespace App\Policies\Pak;

class FunctionalCategoryPolicy extends FoundationPolicy
{
    protected string $viewPermission = 'pak.master.view';

    protected ?string $managePermission = 'pak.master.manage';
}
