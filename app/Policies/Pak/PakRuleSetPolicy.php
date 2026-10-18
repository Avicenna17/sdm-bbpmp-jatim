<?php

namespace App\Policies\Pak;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PakRuleSetPolicy extends FoundationPolicy
{
    protected string $viewPermission = 'pak.rule-set.view';

    protected ?string $managePermission = 'pak.rule-set.manage';

    public function update(User $user, Model $record): bool
    {
        return parent::update($user, $record) && $record->status === 'DRAFT' && $record->first_used_at === null;
    }
}
