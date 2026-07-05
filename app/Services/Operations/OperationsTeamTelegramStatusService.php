<?php

namespace App\Services\Operations;

use App\Models\User;

class OperationsTeamTelegramStatusService
{
    public function __construct(
        private readonly OperationsRoleService $roleService,
    ) {}

    /**
     * @return list<array{id: int, name: string, connected: bool, status_label: string}>
     */
    public function members(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->operationalRoleSlugs()))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'connected' => $this->isConnected($user),
                'status_label' => $this->isConnected($user) ? 'Connected' : 'Not connected',
            ])
            ->all();
    }

    private function isConnected(User $user): bool
    {
        return $user->telegram_notifications_enabled
            && is_string($user->telegram_chat_id)
            && trim($user->telegram_chat_id) !== '';
    }
}
