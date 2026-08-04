<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailMessageStatus;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Per-user Service Case email workspace read cursor.
 * No Gmail labels — only "opened conversation" for unread badge.
 */
class IncomingEmailWorkspaceReadState
{
    public function unreadInboundCount(Incident $incident, User $user): int
    {
        $cursor = $this->lastReadInboundId($user, $incident);

        return IncomingEmailConversationService::inboundQuery($incident)
            ->where('status', IncomingEmailMessageStatus::Linked)
            ->when($cursor !== null, fn ($query) => $query->where('id', '>', $cursor))
            ->count();
    }

    public function lastReadInboundId(User $user, Incident $incident): ?int
    {
        $value = Cache::get($this->key($user, $incident));

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    public function markRead(User $user, Incident $incident, int $maxInboundMessageId): void
    {
        if ($maxInboundMessageId <= 0) {
            return;
        }

        $current = $this->lastReadInboundId($user, $incident) ?? 0;

        if ($maxInboundMessageId <= $current) {
            return;
        }

        Cache::forever($this->key($user, $incident), $maxInboundMessageId);
    }

    private function key(User $user, Incident $incident): string
    {
        return sprintf('email_workspace.read_cursor.%d.%d', $user->id, $incident->id);
    }
}
