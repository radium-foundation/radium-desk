<?php

namespace App\Services\Operations;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannelType;
use App\Models\User;
use App\Services\Notifications\NotificationAuthorityService;
use App\Services\Notifications\NotificationRecipientResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Superadmin-only Telegram recipients — no team broadcast, no ops/admin paths.
 */
final class SuperAdminTelegramRecipientService
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipientResolver,
        private readonly NotificationAuthorityService $notificationAuthority,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function eligibleSuperAdminTelegramRecipients(): Collection
    {
        return $this->recipientResolver->ownerRecipients()
            ->filter(fn (User $user): bool => $this->isEligibleSuperAdminTelegramRecipient($user))
            ->values();
    }

    public function isEligibleSuperAdminTelegramRecipient(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            return false;
        }

        return $this->notificationAuthority->shouldDeliver(
            $user,
            NotificationCategory::DailySummary,
            NotificationChannelType::Telegram,
            now(),
            iraTelegramBridge: true,
        );
    }

    public function assertEligibleSuperAdminTelegramRecipient(User $user): bool
    {
        $fresh = $user->fresh(['roles']);
        if ($fresh === null) {
            Log::warning('AW order daily Telegram: recipient user missing.', [
                'user_id' => $user->id,
            ]);

            return false;
        }

        if (! $this->isEligibleSuperAdminTelegramRecipient($fresh)) {
            Log::warning('AW order daily Telegram: recipient failed superadmin Telegram eligibility.', [
                'user_id' => $fresh->id,
            ]);

            return false;
        }

        return true;
    }
}
