<?php

namespace App\Policies\Pak;

class FunctionalPositionLevelPolicy extends FoundationPolicy
{
    protected string $viewPermission = 'pak.master.view';

    protected ?string $managePermission = 'pak.master.manage';
}
