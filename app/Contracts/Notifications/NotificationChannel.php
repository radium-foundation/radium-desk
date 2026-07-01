<?php

namespace App\Contracts\Notifications;

use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\NotificationType;

interface NotificationChannel
{
    public function supports(NotificationType $type): bool;

    public function send(NotificationMessage $message): NotificationResult;
}
