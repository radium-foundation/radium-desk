<?php

namespace App\Services\HybridRealtime;

use App\Enums\NotificationPriority;
use App\Services\SystemSettingsService;

class HybridRealtimeNotificationDeliveryService
{
    public function __construct(
        private readonly SystemSettingsService $systemSettings,
    ) {}

    public function toastDurationMs(): int
    {
        return (int) $this->systemSettings->get('performance.notifications.toast_duration_ms', 5000);
    }

    public function soundEnabled(): bool
    {
        return $this->systemSettings->getBool('performance.notifications.sound_enabled', true);
    }

    public function browserNotificationsEnabled(): bool
    {
        return $this->systemSettings->getBool('performance.notifications.browser_enabled', true);
    }

    public function priorityThreshold(): NotificationPriority
    {
        $raw = (string) $this->systemSettings->get('performance.notifications.priority_threshold', 'normal');

        return NotificationPriority::tryFrom($raw) ?? NotificationPriority::Normal;
    }

    public function shouldDeliver(NotificationPriority $priority): bool
    {
        return $priority->meetsThreshold($this->priorityThreshold());
    }

    public function shouldPlaySound(NotificationPriority $priority, bool $requested): bool
    {
        if (! $requested) {
            return false;
        }

        return $this->soundEnabled() && $this->shouldDeliver($priority);
    }

    public function shouldShowBrowser(NotificationPriority $priority, bool $requested): bool
    {
        if (! $requested) {
            return false;
        }

        if (! $this->browserNotificationsEnabled()) {
            return false;
        }

        return in_array($priority, [NotificationPriority::Critical, NotificationPriority::High], true)
            && $this->shouldDeliver($priority);
    }

    public function shouldShowToast(NotificationPriority $priority, bool $requested): bool
    {
        if (! $requested) {
            return false;
        }

        return $priority !== NotificationPriority::Silent && $this->shouldDeliver($priority);
    }
}
