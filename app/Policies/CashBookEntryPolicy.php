<?php

namespace App\Policies;

use App\Models\CashBookEntry;
use App\Models\User;
use App\Support\CashBook\CashBookAccess;

class CashBookEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return CashBookAccess::allowsView($user);
    }

    public function view(User $user, CashBookEntry $cashBookEntry): bool
    {
        return CashBookAccess::allowsView($user);
    }

    public function create(User $user): bool
    {
        return CashBookAccess::allowsCreate($user);
    }

    public function update(User $user, CashBookEntry $cashBookEntry): bool
    {
        return CashBookAccess::allowsManage($user);
    }

    public function delete(User $user, CashBookEntry $cashBookEntry): bool
    {
        return CashBookAccess::allowsManage($user);
    }
}
