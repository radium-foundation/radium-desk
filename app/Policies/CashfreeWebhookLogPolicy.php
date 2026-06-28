<?php

namespace App\Policies;

use App\Models\CashfreeWebhookLog;
use App\Models\User;

class CashfreeWebhookLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cashfree-webhook-logs.view');
    }

    public function view(User $user, CashfreeWebhookLog $cashfreeWebhookLog): bool
    {
        return $user->can('cashfree-webhook-logs.view');
    }
}
