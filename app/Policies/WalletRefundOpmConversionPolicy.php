<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WalletRefundOpmConversion;

class WalletRefundOpmConversionPolicy
{
    public function create(User $user): bool
    {
        return $user->can('refunds.create');
    }

    public function view(User $user, WalletRefundOpmConversion $conversion): bool
    {
        return $user->can('refunds.view');
    }
}
