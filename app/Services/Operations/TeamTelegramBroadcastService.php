<?php

namespace App\Services\Operations;

use App\Enums\TeamBroadcastAudience;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

class TeamTelegramBroadcastService
{
    public function __construct(
        private readonly IraCommunicationService $communicationService,
        private readonly OperationsRoleService $roleService,
    ) {}

    /**
     * @param  list<int>  $selectedUserIds
     * @return array{recipients: int, sent: int, skipped: int, failed: int}
     */
    public function broadcast(
        User $sender,
        string $message,
        TeamBroadcastAudience $audience,
        array $selectedUserIds = [],
        ?string $subject = null,
    ): array {
        $recipients = $this->resolveRecipients($audience, $selectedUserIds);
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            $results = $this->communicationService->sendTeamAnnouncement(
                recipient: $recipient,
                message: $message,
                sender: $sender,
                subject: $subject,
            );

            foreach ($results as $notification) {
                match ($notification->status->value) {
                    'sent' => $sent++,
                    'failed' => $failed++,
                    default => $skipped++,
                };
            }
        }

        return [
            'recipients' => count($recipients),
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * @param  list<int>  $selectedUserIds
     * @return list<User>
     */
    public function resolveRecipients(TeamBroadcastAudience $audience, array $selectedUserIds = []): array
    {
        return match ($audience) {
            TeamBroadcastAudience::AllTeam => $this->allTeamRecipients(),
            TeamBroadcastAudience::OperationsTeam => $this->operationsTeamRecipients(),
            TeamBroadcastAudience::Selected => $this->selectedRecipients($selectedUserIds),
        };
    }

    /**
     * @return list<User>
     */
    private function allTeamRecipients(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->operationalRoleSlugs()))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->filter(fn (User $user): bool => $this->hasTelegramConfigured($user))
            ->values()
            ->all();
    }

    /**
     * @return list<User>
     */
    private function operationsTeamRecipients(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', [
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_AGENT,
                RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
                RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
                RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST,
            ]))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->filter(fn (User $user): bool => $this->hasTelegramConfigured($user))
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $selectedUserIds
     * @return list<User>
     */
    private function selectedRecipients(array $selectedUserIds): array
    {
        $ids = array_values(array_filter(
            array_map('intval', $selectedUserIds),
            fn (int $id): bool => $id > 0,
        ));

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (User $user): bool => $this->hasTelegramConfigured($user))
            ->values()
            ->all();
    }

    private function hasTelegramConfigured(User $user): bool
    {
        return $user->telegram_notifications_enabled
            && is_string($user->telegram_chat_id)
            && trim($user->telegram_chat_id) !== '';
    }
}
