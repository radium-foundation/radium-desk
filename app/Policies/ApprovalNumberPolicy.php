<?php

namespace App\Policies;

use App\Models\ApprovalNumber;
use App\Models\User;

class ApprovalNumberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('approvals.view');
    }

    public function view(User $user, ApprovalNumber $approvalNumber): bool
    {
        return $user->can('approvals.view');
    }

    public function create(User $user): bool
    {
        return $user->can('approvals.create');
    }

    public function link(User $user, ApprovalNumber $approvalNumber): bool
    {
        return $user->can('approvals.link');
    }

    public function delete(User $user, ApprovalNumber $approvalNumber): bool
    {
        return $user->can('approvals.delete');
    }
}
