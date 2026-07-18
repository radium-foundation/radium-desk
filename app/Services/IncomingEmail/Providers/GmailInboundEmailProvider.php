<?php

namespace App\Services\IncomingEmail\Providers;

use App\Contracts\IncomingEmail\InboundEmailProvider;
use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Models\GmailMailboxSyncState;
use App\Services\IncomingEmail\Gmail\GmailApiClient;
use App\Services\IncomingEmail\Gmail\GmailMessageMapper;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Live Gmail provider. Uses historyId incremental sync.
 * First pull for a mailbox baselines the cursor and returns no messages (no history import).
 * Call commitCursor() after successful ingest so failures can safely retry.
 */
class GmailInboundEmailProvider implements InboundEmailProvider
{
    private ?string $mailbox = null;

    private ?string $pendingHistoryId = null;

    public function __construct(
        private readonly GmailApiClient $apiClient,
        private readonly GmailMessageMapper $mapper,
    ) {}

    public function forMailbox(string $mailbox): self
    {
        $clone = clone $this;
        $clone->mailbox = strtolower(trim($mailbox));
        $clone->pendingHistoryId = null;

        return $clone;
    }

    /**
     * @return list<NormalizedInboundEmail>
     */
    public function pull(): array
    {
        if ($this->mailbox === null || $this->mailbox === '') {
            throw new RuntimeException('GmailInboundEmailProvider requires forMailbox() before pull().');
        }

        $this->pendingHistoryId = null;

        $state = GmailMailboxSyncState::query()->firstOrCreate(
            ['mailbox' => $this->mailbox],
            ['enabled_at' => now()],
        );

        if ($state->enabled_at === null) {
            $state->update(['enabled_at' => now()]);
            $state = $state->fresh();
        }

        if (! $state->isBaselined()) {
            return $this->baselineMailbox($state);
        }

        return $this->pullSinceHistory($state);
    }

    public function pendingHistoryId(): ?string
    {
        return $this->pendingHistoryId;
    }

    public function commitCursor(): void
    {
        if ($this->mailbox === null || $this->pendingHistoryId === null) {
            return;
        }

        GmailMailboxSyncState::query()
            ->where('mailbox', $this->mailbox)
            ->update([
                'history_id' => $this->pendingHistoryId,
                'last_synced_at' => now(),
                'last_error' => null,
            ]);

        $this->pendingHistoryId = null;
    }

    public function recordError(string $message): void
    {
        if ($this->mailbox === null) {
            return;
        }

        GmailMailboxSyncState::query()
            ->where('mailbox', $this->mailbox)
            ->update([
                'last_error' => mb_substr($message, 0, 1000),
            ]);
    }

    /**
     * @return list<NormalizedInboundEmail>
     */
    private function baselineMailbox(GmailMailboxSyncState $state): array
    {
        $profile = $this->apiClient->getProfile($this->mailbox);

        $state->update([
            'history_id' => $profile['historyId'],
            'baselined_at' => now(),
            'last_synced_at' => now(),
            'last_error' => null,
        ]);

        Log::info('[GmailInbound] Mailbox baselined; historical mail will not be imported.', [
            'mailbox' => $this->mailbox,
            'history_id' => $profile['historyId'],
        ]);

        // Baseline has nothing to commit later.
        $this->pendingHistoryId = null;

        return [];
    }

    /**
     * @return list<NormalizedInboundEmail>
     */
    private function pullSinceHistory(GmailMailboxSyncState $state): array
    {
        $history = $this->apiClient->listHistoryMessageIds(
            $this->mailbox,
            (string) $state->history_id,
        );

        if ($history['expired']) {
            Log::warning('[GmailInbound] historyId expired; re-baselining without backfill.', [
                'mailbox' => $this->mailbox,
                'previous_history_id' => $state->history_id,
            ]);

            return $this->baselineMailbox($state->fresh() ?? $state);
        }

        $messages = [];

        foreach ($history['messageIds'] as $messageId) {
            $raw = $this->apiClient->getMessage($this->mailbox, $messageId);
            $messages[] = $this->mapper->toNormalized($this->mailbox, $raw);
        }

        $this->pendingHistoryId = $history['historyId'];

        return $messages;
    }
}
