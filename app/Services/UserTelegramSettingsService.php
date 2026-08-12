<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserTelegramSettingsService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function isConnected(User $user): bool
    {
        return $user->telegram_notifications_enabled
            && is_string($user->telegram_chat_id)
            && trim($user->telegram_chat_id) !== '';
    }

    public function canSelfServiceConnect(User $user): bool
    {
        return ! $user->can('users.manage') && blank($user->telegram_chat_id);
    }

    public function isSelfServiceLocked(User $user): bool
    {
        return ! $user->can('users.manage') && filled($user->telegram_chat_id);
    }

    /**
     * @return array{
     *     connected: bool,
     *     can_self_service_connect: bool,
     *     is_self_service_locked: bool,
     *     notifications_enabled: bool,
     *     chat_id: string|null
     * }
     */
    public function snapshotForProfile(User $user): array
    {
        return [
            'connected' => $this->isConnected($user),
            'can_self_service_connect' => $this->canSelfServiceConnect($user),
            'is_self_service_locked' => $this->isSelfServiceLocked($user),
            'notifications_enabled' => (bool) $user->telegram_notifications_enabled,
            'chat_id' => filled($user->telegram_chat_id) ? (string) $user->telegram_chat_id : null,
        ];
    }

    /**
     * @return array{
     *     connected: bool,
     *     notifications_enabled: bool,
     *     chat_id: string|null
     * }
     */
    public function snapshotForAdmin(User $user): array
    {
        return [
            'connected' => $this->isConnected($user),
            'notifications_enabled' => (bool) $user->telegram_notifications_enabled,
            'chat_id' => filled($user->telegram_chat_id) ? (string) $user->telegram_chat_id : null,
        ];
    }

    /**
     * @param  array{telegram_chat_id?: string|null, telegram_notifications_enabled?: bool}  $validated
     */
    public function applyProfileUpdateForManager(User $user, array $validated): void
    {
        $chatId = $this->normalizeChatId($validated['telegram_chat_id'] ?? null);
        $enabled = (bool) ($validated['telegram_notifications_enabled'] ?? false);

        if ($chatId === null) {
            $enabled = false;
        }

        $user->fill([
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => $enabled,
        ]);
        $user->save();
    }

    public function applyProfileConnect(User $user, ?string $chatId): void
    {
        if ($user->can('users.manage')) {
            throw new AuthorizationException('Use profile manager update for users with manage access.');
        }

        if (filled($user->telegram_chat_id)) {
            throw new AuthorizationException('Telegram settings are locked. Contact an administrator to change your connection.');
        }

        $normalizedChatId = $this->normalizeChatId($chatId);

        if ($normalizedChatId === null) {
            throw new AuthorizationException('A Telegram Chat ID is required to connect.');
        }

        $user->fill([
            'telegram_chat_id' => $normalizedChatId,
            'telegram_notifications_enabled' => true,
        ]);
        $user->save();
    }

    /**
     * @param  array{
     *     telegram_chat_id?: string|null,
     *     telegram_notifications_enabled?: bool,
     *     reset?: bool
     * }  $validated
     */
    public function updateForUser(User $target, User $actor, array $validated, Request $request): void
    {
        if ((bool) ($validated['reset'] ?? false)) {
            $this->resetForUser($target, $actor, $request);

            return;
        }

        $chatId = $this->normalizeChatId($validated['telegram_chat_id'] ?? null);
        $enabled = (bool) ($validated['telegram_notifications_enabled'] ?? false);

        if ($chatId === null) {
            $enabled = false;
        }

        DB::transaction(function () use ($target, $actor, $chatId, $enabled, $request): void {
            $oldSnapshot = $this->auditSnapshot($target);

            $target->fill([
                'telegram_chat_id' => $chatId,
                'telegram_notifications_enabled' => $enabled,
            ]);
            $target->save();

            $newSnapshot = $this->auditSnapshot($target->fresh());

            if ($oldSnapshot !== $newSnapshot) {
                $this->auditLogService->log(
                    userId: $actor->id,
                    event: 'user.telegram.updated',
                    auditable: $target,
                    oldValues: $oldSnapshot,
                    newValues: $newSnapshot,
                    request: $request,
                );
            }
        });
    }

    private function resetForUser(User $target, User $actor, Request $request): void
    {
        DB::transaction(function () use ($target, $actor, $request): void {
            $oldSnapshot = $this->auditSnapshot($target);

            $target->fill([
                'telegram_chat_id' => null,
                'telegram_notifications_enabled' => false,
            ]);
            $target->save();

            $newSnapshot = $this->auditSnapshot($target->fresh());

            if ($oldSnapshot !== $newSnapshot) {
                $this->auditLogService->log(
                    userId: $actor->id,
                    event: 'user.telegram.updated',
                    auditable: $target,
                    oldValues: $oldSnapshot,
                    newValues: $newSnapshot,
                    request: $request,
                );
            }
        });
    }

    private function normalizeChatId(?string $chatId): ?string
    {
        if (! is_string($chatId)) {
            return null;
        }

        $trimmed = trim($chatId);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{telegram_chat_id_fingerprint: string|null, telegram_notifications_enabled: bool}
     */
    private function auditSnapshot(User $user): array
    {
        return [
            'telegram_chat_id_fingerprint' => $this->fingerprintChatId($user->telegram_chat_id),
            'telegram_notifications_enabled' => (bool) $user->telegram_notifications_enabled,
        ];
    }

    private function fingerprintChatId(?string $chatId): ?string
    {
        if (! is_string($chatId) || trim($chatId) === '') {
            return null;
        }

        return substr(hash('sha256', trim($chatId)), 0, 12);
    }
}
