<?php

namespace App\Policies;

use App\Models\RefundRequest;
use App\Models\User;

class RefundRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('refunds.view');
    }

    public function view(User $user, RefundRequest $refundRequest): bool
    {
        return $user->can('refunds.view');
    }

    public function create(User $user): bool
    {
        return $user->can('refunds.create');
    }

    public function review(User $user, RefundRequest $refundRequest): bool
    {
        return $user->can('refunds.review');
    }

    public function delete(User $user, RefundRequest $refundRequest): bool
    {
        return $user->can('refunds.delete');
    }
}
