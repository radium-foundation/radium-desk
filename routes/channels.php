<?php

use App\Models\User;
use App\Services\DashboardChannelAuthorization;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, $id): bool {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('dashboard.{userId}', function (User $user, $userId): bool {
    return DashboardChannelAuthorization::canSubscribeToDashboard($user, $userId);
});

Broadcast::channel('notifications.{userId}', function (User $user, $userId): bool {
    return DashboardChannelAuthorization::canSubscribeToNotifications($user, $userId);
});

Broadcast::channel('dashboard.incident.{incidentId}', function (User $user, $incidentId): bool {
    return DashboardChannelAuthorization::canSubscribeToIncident($user, $incidentId);
});
