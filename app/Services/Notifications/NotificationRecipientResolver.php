<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;

class NotificationRecipientResolver
{
    /**
     * Superadmin owners who receive daily briefings, risk alerts, and system health signals.
     *
     * @return Collection<int, User>
     */
    public function ownerRecipients(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', RolePermissionSeeder::ROLE_SUPERADMIN))
            ->get();
    }

    /**
     * Operations leadership who receive operational risk and leave-review style alerts.
     *
     * @return Collection<int, User>
     */
    public function operationalRecipients(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', [
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_ADMIN,
            ]))
            ->get();
    }

    public function assignmentRecipient(?int $userId): ?User
    {
        if (! is_numeric($userId)) {
            return null;
        }

        return User::query()
            ->where('is_active', true)
            ->find((int) $userId);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return Collection<int, User>
     */
    public function recipientsFor(NotificationCategory $category, array $context = []): Collection
    {
        $explicitRecipient = $this->assignmentRecipient(
            is_numeric($context['user_id'] ?? null) ? (int) $context['user_id'] : null,
        );

        if ($explicitRecipient !== null) {
            return collect([$explicitRecipient]);
        }

        return match ($category) {
            NotificationCategory::DailySummary,
            NotificationCategory::SystemHealth,
            NotificationCategory::Escalation => $this->ownerRecipients(),
            NotificationCategory::LeaveApprovals => $this->operationalRecipients(),
            NotificationCategory::Assignment,
            NotificationCategory::Ivr,
            NotificationCategory::Finance => collect(),
        };
    }
}
