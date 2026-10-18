<?php

namespace App\Policies\Pak;

class PakEventPolicy extends FoundationPolicy
{
    protected string $viewPermission = 'pak.audit.view';
}
