<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationScheduleMode;
use App\Enums\WorkCalendarDayStatus;
use App\Models\User;
use App\Services\Operations\WorkCalendarService;
use App\Services\SettingService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;

class NotificationAuthorityService
{
    public function __construct(
        private readonly SettingService $settingService,
        private readonly SystemSettingsService $systemSettings,
        private readonly WorkCalendarService $workCalendarService,
    ) {}

    public function shouldDeliver(
        User $user,
        NotificationCategory $category,
        NotificationChannelType $channel,
        ?Carbon $at = null,
        bool $iraTelegramBridge = false,
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if ($iraTelegramBridge) {
            return $channel === NotificationChannelType::Telegram
                && $this->channelEnabled($channel)
                && $this->userAllows($user, $channel);
        }

        if (! $this->categoryEnabled($category)) {
            return false;
        }

        if (! $this->channelEnabled($channel)) {
            return false;
        }

        if (! $this->userAllows($user, $channel)) {
            return false;
        }

        return $this->scheduleAllows($user, $category, $at);
    }

    public function categoryEnabled(NotificationCategory $category): bool
    {
        return match ($category) {
            NotificationCategory::Assignment => $this->settingService->getBool('notifications.assignment_enabled', true),
            NotificationCategory::Finance => $this->settingService->getBool('notifications.transaction_enabled', true),
            NotificationCategory::Escalation => $this->settingService->getBool('notifications.high_priority_enabled', true),
            NotificationCategory::Ivr,
            NotificationCategory::LeaveApprovals,
            NotificationCategory::DailySummary,
            NotificationCategory::SystemHealth => true,
        };
    }

    public function channelEnabled(NotificationChannelType $channel): bool
    {
        return match ($channel) {
            NotificationChannelType::InApp => true,
            NotificationChannelType::Telegram => $this->systemSettings->getBool('notifications.telegram.enabled', false),
            NotificationChannelType::Email => $this->systemSettings->getBool('notifications.email.enabled', false),
            NotificationChannelType::WhatsApp => $this->systemSettings->getBool('notifications.whatsapp.enabled', true),
            NotificationChannelType::Desktop => $this->systemSettings->getBool('notifications.desktop.enabled', false),
        };
    }

    public function userAllows(User $user, NotificationChannelType $channel): bool
    {
        return match ($channel) {
            NotificationChannelType::Telegram => $user->telegram_notifications_enabled
                && filled($user->telegram_chat_id),
            NotificationChannelType::Email => filled($user->email),
            NotificationChannelType::InApp,
            NotificationChannelType::Desktop,
            NotificationChannelType::WhatsApp => true,
        };
    }

    public function scheduleAllows(
        User $user,
        NotificationCategory $category,
        ?Carbon $at = null,
    ): bool {
        $mode = $this->defaultScheduleModeFor($user);

        return match ($mode) {
            NotificationScheduleMode::Always => true,
            NotificationScheduleMode::WorkHours => $this->scheduleAllowsWorkHours($user, $at),
            NotificationScheduleMode::ExtendedHours => $this->scheduleAllowsExtendedHours($user, $at),
            NotificationScheduleMode::Custom => $this->scheduleAllowsWorkHours($user, $at),
        };
    }

    public function defaultScheduleModeFor(User $user): NotificationScheduleMode
    {
        if ($user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            return NotificationScheduleMode::Always;
        }

        if ($user->hasAnyRole([
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            RolePermissionSeeder::ROLE_ADMIN,
        ])) {
            return NotificationScheduleMode::ExtendedHours;
        }

        return NotificationScheduleMode::WorkHours;
    }

    private function scheduleAllowsWorkHours(User $user, ?Carbon $at = null): bool
    {
        $status = $this->calendarStatus($user, $at);

        return $status === WorkCalendarDayStatus::Working;
    }

    private function scheduleAllowsExtendedHours(User $user, ?Carbon $at = null): bool
    {
        $status = $this->calendarStatus($user, $at);

        if ($status === null) {
            return false;
        }

        return ! in_array($status, [
            WorkCalendarDayStatus::LeaveApproved,
            WorkCalendarDayStatus::Holiday,
            WorkCalendarDayStatus::WeeklyOff,
            WorkCalendarDayStatus::OutsideHours,
        ], true);
    }

    private function calendarStatus(User $user, ?Carbon $at = null): ?WorkCalendarDayStatus
    {
        $snapshot = $this->workCalendarService->todayStatusFor($user, $at ?? now());

        return WorkCalendarDayStatus::tryFrom((string) ($snapshot['status'] ?? ''));
    }
}
