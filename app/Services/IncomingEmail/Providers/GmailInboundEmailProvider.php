<?php

namespace App\Services\IncomingEmail\Providers;

use App\Contracts\IncomingEmail\InboundEmailProvider;
use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Models\GmailMailboxSyncState;
use App\Services\IncomingEmail\Gmail\GmailApiClient;
use App\Services\IncomingEmail\Gmail\GmailApiException;
use App\Services\IncomingEmail\Gmail\GmailMessageFetchException;
use App\Services\IncomingEmail\Gmail\GmailMessageMapper;
use App\Services\IncomingEmail\Gmail\GmailStaleMessageException;
use App\Services\IncomingEmail\Gmail\GmailSyncMetricsService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Live Gmail provider. Uses historyId incremental sync with per-entry cursor commits.
 * First pull for a mailbox baselines the cursor and returns no messages (no history import).
 */
class GmailInboundEmailProvider implements InboundEmailProvider
{
    private ?string $mailbox = null;

    private ?string $pendingHistoryId = null;

    private int $staleMessageSkips = 0;

    private int $fetchFailures = 0;

    private int $retries = 0;

    private int $historyPages = 0;

    private int $cursorAdvances = 0;

    private int $messagesFetched = 0;

    private ?int $lastLatencyMs = null;

    public function __construct(
        private readonly GmailApiClient $apiClient,
        private readonly GmailMessageMapper $mapper,
        private readonly GmailSyncMetricsService $metrics,
    ) {}

    public function forMailbox(string $mailbox): self
    {
        $clone = clone $this;
        $clone->mailbox = strtolower(trim($mailbox));
        $clone->pendingHistoryId = null;
        $clone->staleMessageSkips = 0;
        $clone->fetchFailures = 0;
        $clone->retries = 0;
        $clone->historyPages = 0;
        $clone->cursorAdvances = 0;
        $clone->messagesFetched = 0;
        $clone->lastLatencyMs = null;

        return $clone;
    }

    /**
     * @return list<NormalizedInboundEmail>
     */
    public function pull(): array
    {
        $collected = [];

        $this->pullIncremental(function (array $messages) use (&$collected): void {
            array_push($collected, ...$messages);
        });

        return $collected;
    }

    /**
     * Process history page-by-page / entry-by-entry.
     * After each history entry is fetched (with isolated message failures), invokes $onBatch
     * then commits the entry history id so a crash can resume without replaying the backlog.
     *
     * @param  callable(list<NormalizedInboundEmail>): void  $onBatch
     * @return array{
     *     stale_skipped: int,
     *     fetch_failed: int,
     *     retried: int,
     *     pages: int,
     *     cursor_advances: int,
     *     messages_fetched: int,
     *     last_latency_ms: ?int
     * }
     */
    public function pullIncremental(callable $onBatch): array
    {
        if ($this->mailbox === null || $this->mailbox === '') {
            throw new RuntimeException('GmailInboundEmailProvider requires forMailbox() before pull().');
        }

        $this->pendingHistoryId = null;
        $this->staleMessageSkips = 0;
        $this->fetchFailures = 0;
        $this->retries = 0;
        $this->historyPages = 0;
        $this->cursorAdvances = 0;
        $this->messagesFetched = 0;
        $this->lastLatencyMs = null;

        $state = GmailMailboxSyncState::query()->firstOrCreate(
            ['mailbox' => $this->mailbox],
            ['enabled_at' => now()],
        );

        if ($state->enabled_at === null) {
            $state->update(['enabled_at' => now()]);
            $state = $state->fresh() ?? $state;
        }

        $state->update(['last_attempted_at' => now()]);

        if (! $state->isBaselined()) {
            $this->baselineMailbox($state);

            return $this->stats();
        }

        $startHistoryId = (string) $state->history_id;
        $pageToken = null;

        do {
            $page = $this->apiClient->listHistoryPage($this->mailbox, $startHistoryId, $pageToken);
            $this->retries += $page['retries'];
            $this->lastLatencyMs = $page['latency_ms'];
            $this->historyPages++;

            if ($page['expired']) {
                Log::warning('[GmailInbound] historyId expired; re-baselining without backfill.', [
                    'mailbox' => $this->mailbox,
                    'previous_history_id' => $startHistoryId,
                ]);

                $this->baselineMailbox($state->fresh() ?? $state);

                return $this->stats();
            }

            if ($page['retries'] > 0) {
                $this->metrics->incrementToday($this->mailbox, 'retried', $page['retries']);
            }

            $pendingPageCommitId = null;

            foreach ($page['entries'] as $entry) {
                $commitId = $entry['id'] !== '' ? $entry['id'] : $page['historyId'];
                $messages = $this->fetchMessagesForEntry($entry['messageIds'], $commitId);
                $onBatch($messages);

                if ($entry['id'] !== '') {
                    $this->commitCursorTo($entry['id']);
                } else {
                    $pendingPageCommitId = $page['historyId'];
                }
            }

            if ($pendingPageCommitId !== null && $pendingPageCommitId !== '') {
                $this->commitCursorTo($pendingPageCommitId, $page['historyId']);
            }

            $pageToken = $page['nextPageToken'];

            if ($pageToken === null) {
                $this->touchSyncedAt($page['historyId']);

                $current = (string) (GmailMailboxSyncState::query()->where('mailbox', $this->mailbox)->value('history_id') ?? '');
                if ($page['historyId'] !== '' && $page['historyId'] !== $current) {
                    $this->commitCursorTo($page['historyId'], $page['historyId']);
                }
            }
        } while ($pageToken !== null);

        return $this->stats();
    }

    public function pendingHistoryId(): ?string
    {
        return $this->pendingHistoryId;
    }

    public function staleMessageSkips(): int
    {
        return $this->staleMessageSkips;
    }

    public function fetchFailures(): int
    {
        return $this->fetchFailures;
    }

    public function retries(): int
    {
        return $this->retries;
    }

    public function historyPages(): int
    {
        return $this->historyPages;
    }

    public function cursorAdvances(): int
    {
        return $this->cursorAdvances;
    }

    public function messagesFetched(): int
    {
        return $this->messagesFetched;
    }

    public function lastLatencyMs(): ?int
    {
        return $this->lastLatencyMs;
    }

    public function commitCursor(): void
    {
        if ($this->mailbox === null || $this->pendingHistoryId === null) {
            return;
        }

        $this->commitCursorTo($this->pendingHistoryId);
        $this->pendingHistoryId = null;
    }

    public function commitCursorTo(string $historyId, ?string $profileHistoryId = null): void
    {
        if ($this->mailbox === null || $historyId === '') {
            return;
        }

        $payload = [
            'history_id' => $historyId,
            'last_synced_at' => now(),
            'last_error' => null,
            'consecutive_failures' => 0,
        ];

        if ($profileHistoryId !== null) {
            $payload['profile_history_id'] = $profileHistoryId;
        }

        GmailMailboxSyncState::query()
            ->where('mailbox', $this->mailbox)
            ->update($payload);

        $this->cursorAdvances++;
        $this->metrics->incrementToday($this->mailbox, 'cursor_advances');
        $this->pendingHistoryId = $historyId;
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
                'last_attempted_at' => now(),
            ]);

        GmailMailboxSyncState::query()
            ->where('mailbox', $this->mailbox)
            ->increment('consecutive_failures');
    }

    public function recordRunMetrics(
        int $processed,
        int $skipped,
        int $retried,
        int $failed,
        int $pages,
        int $cursorAdvances,
        int $durationMs,
        ?int $latencyMs,
        string $oauthStatus = 'ok',
    ): void {
        if ($this->mailbox === null) {
            return;
        }

        GmailMailboxSyncState::query()
            ->where('mailbox', $this->mailbox)
            ->update([
                'messages_processed_last_run' => $processed,
                'messages_skipped_last_run' => $skipped,
                'messages_retried_last_run' => $retried,
                'messages_failed_last_run' => $failed,
                'history_pages_last_run' => $pages,
                'cursor_advances_last_run' => $cursorAdvances,
                'last_sync_duration_ms' => $durationMs,
                'last_response_latency_ms' => $latencyMs,
                'oauth_status' => $oauthStatus,
                'last_attempted_at' => now(),
            ]);
    }

    /**
     * Re-baseline from Gmail profile. Skips historical import.
     */
    public function rebaseline(): string
    {
        if ($this->mailbox === null || $this->mailbox === '') {
            throw new RuntimeException('GmailInboundEmailProvider requires forMailbox() before rebaseline().');
        }

        $state = GmailMailboxSyncState::query()->firstOrCreate(
            ['mailbox' => $this->mailbox],
            ['enabled_at' => now()],
        );

        return $this->baselineMailbox($state);
    }

    /**
     * @return list<NormalizedInboundEmail>
     */
    private function fetchMessagesForEntry(array $messageIds, string $historyEntryId): array
    {
        $messages = [];
        $seen = [];

        foreach ($messageIds as $messageId) {
            if (isset($seen[$messageId])) {
                continue;
            }
            $seen[$messageId] = true;

            try {
                $raw = $this->apiClient->getMessage($this->mailbox, $messageId, $historyEntryId);
                $messageRetries = $this->apiClient->lastRequestRetries();
                if ($messageRetries > 0) {
                    $this->retries += $messageRetries;
                    $this->metrics->incrementToday($this->mailbox, 'retried', $messageRetries);
                }
                $messages[] = $this->mapper->toNormalized($this->mailbox, $raw);
                $this->messagesFetched++;
            } catch (GmailStaleMessageException $exception) {
                $this->staleMessageSkips++;
                $this->metrics->incrementToday($this->mailbox, 'skipped');
                Log::warning('[GmailInbound] Stale history message not found; skipping.', $exception->context());
            } catch (GmailMessageFetchException $exception) {
                $this->fetchFailures++;
                $this->retries += max(0, $exception->attemptCount - 1);
                $this->metrics->recordFailure(
                    $exception->mailbox,
                    (string) $exception->messageId,
                    $exception->endpoint,
                    $exception->httpStatus,
                    $exception->errorPayload,
                    $exception->historyId,
                    $exception->attemptCount,
                    $exception->elapsedMs,
                    $exception->requestId,
                );
                $this->metrics->logFailure('Message fetch failed after retries; skipping.', $exception->context());
            } catch (GmailApiException $exception) {
                $this->fetchFailures++;
                $this->retries += max(0, $exception->attemptCount - 1);
                $this->metrics->recordFailure(
                    $exception->mailbox,
                    (string) ($exception->messageId ?? $messageId),
                    $exception->endpoint,
                    $exception->httpStatus,
                    $exception->errorPayload,
                    $exception->historyId ?? $historyEntryId,
                    $exception->attemptCount,
                    $exception->elapsedMs,
                    $exception->requestId,
                );
                $this->metrics->logFailure('Message fetch failed after retries; skipping.', $exception->context());
            }
        }

        return $messages;
    }

    private function baselineMailbox(GmailMailboxSyncState $state): string
    {
        $profile = $this->apiClient->getProfile($this->mailbox);

        $state->update([
            'history_id' => $profile['historyId'],
            'profile_history_id' => $profile['historyId'],
            'baselined_at' => now(),
            'last_synced_at' => now(),
            'last_attempted_at' => now(),
            'last_error' => null,
            'consecutive_failures' => 0,
            'oauth_status' => 'ok',
        ]);

        Log::info('[GmailInbound] Mailbox baselined; historical mail will not be imported.', [
            'mailbox' => $this->mailbox,
            'history_id' => $profile['historyId'],
        ]);

        $this->pendingHistoryId = null;
        $this->cursorAdvances++;

        return $profile['historyId'];
    }

    private function touchSyncedAt(?string $profileHistoryId): void
    {
        if ($this->mailbox === null) {
            return;
        }

        $payload = [
            'last_synced_at' => now(),
            'last_error' => null,
            'consecutive_failures' => 0,
        ];

        if ($profileHistoryId !== null && $profileHistoryId !== '') {
            $payload['profile_history_id'] = $profileHistoryId;
        }

        GmailMailboxSyncState::query()
            ->where('mailbox', $this->mailbox)
            ->update($payload);
    }

    /**
     * @return array{
     *     stale_skipped: int,
     *     fetch_failed: int,
     *     retried: int,
     *     pages: int,
     *     cursor_advances: int,
     *     messages_fetched: int,
     *     last_latency_ms: ?int
     * }
     */
    private function stats(): array
    {
        return [
            'stale_skipped' => $this->staleMessageSkips,
            'fetch_failed' => $this->fetchFailures,
            'retried' => $this->retries,
            'pages' => $this->historyPages,
            'cursor_advances' => $this->cursorAdvances,
            'messages_fetched' => $this->messagesFetched,
            'last_latency_ms' => $this->lastLatencyMs,
        ];
    }
}
