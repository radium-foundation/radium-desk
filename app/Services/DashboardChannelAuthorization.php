<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\User;

class DashboardChannelAuthorization
{
    public static function canSubscribeToDashboard(User $user, int|string $userId): bool
    {
        return (int) $user->id === (int) $userId && $user->can('incidents.view');
    }

    public static function canSubscribeToNotifications(User $user, int|string $userId): bool
    {
        return (int) $user->id === (int) $userId;
    }

    public static function canSubscribeToIncident(User $user, int|string $incidentId): bool
    {
        $incident = Incident::query()->find($incidentId);

        return $incident !== null && $user->can('view', $incident);
    }
}
