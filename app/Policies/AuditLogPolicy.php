<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit-logs.view');
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->can('audit-logs.view');
    }
}
