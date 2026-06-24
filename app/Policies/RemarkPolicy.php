<?php

namespace App\Policies;

use App\Models\Remark;
use App\Models\User;

class RemarkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('remarks.view');
    }

    public function view(User $user, Remark $remark): bool
    {
        return $user->can('remarks.view');
    }

    public function create(User $user): bool
    {
        return $user->can('remarks.create');
    }

    public function delete(User $user, Remark $remark): bool
    {
        return $user->can('remarks.delete');
    }
}
