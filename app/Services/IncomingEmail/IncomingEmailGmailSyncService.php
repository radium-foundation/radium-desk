<?php

namespace App\Services\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailMessageStatus;
use App\Models\GmailMailboxSyncState;
use App\Services\IncomingEmail\Gmail\GmailSyncMetricsService;
use App\Services\IncomingEmail\Providers\GmailInboundEmailProvider;
use App\Services\Operations\OperationsGmailHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Orchestrates Gmail pull → IncomingEmailIngestService::ingest only.
 * Does not contain matching, filtering, or incident logic.
 */
class IncomingEmailGmailSyncService
{
    private const MAILBOX_LOCK_SECONDS = 120;

    public function __construct(
        private readonly GmailInboundEmailProvider $gmailProvider,
        private readonly IncomingEmailIngestService $ingestService,
        private readonly GmailSyncMetricsService $metrics,
        private readonly OperationsGmailHealthService $gmailHealth,
    ) {}

    /**
     * @return array{
     *     mailboxes: int,
     *     pulled: int,
     *     ingested: int,
     *     skipped: int,
     *     stale_messages_skipped: int,
     *     messages_failed: int,
     *     messages_retried: int,
     *     history_pages: int,
     *     cursor_advances: int,
     *     failed_mailboxes: int
     * }
     */
    public function sync(): array
    {
        if (! config('inbound_email.enabled') || ! config('inbound_email.gmail.enabled')) {
            return [
                'mailboxes' => 0,
                'pulled' => 0,
                'ingested' => 0,
                'skipped' => 0,
                'stale_messages_skipped' => 0,
                'messages_failed' => 0,
                'messages_retried' => 0,
                'history_pages' => 0,
                'cursor_advances' => 0,
                'failed_mailboxes' => 0,
            ];
        }

        $this->assertGmailConfigured();

        $mailboxes = $this->syncMailboxes();
        $pulled = 0;
        $ingested = 0;
        $skipped = 0;
        $staleMessagesSkipped = 0;
        $messagesFailed = 0;
        $messagesRetried = 0;
        $historyPages = 0;
        $cursorAdvances = 0;
        $failedMailboxes = 0;

        foreach ($mailboxes as $mailbox) {
            $lock = Cache::lock($this->mailboxLockKey($mailbox), self::MAILBOX_LOCK_SECONDS);

            if (! $lock->get()) {
                $skipped++;
                Log::info('[GmailInbound] Skipping mailbox; sync already in progress.', [
                    'mailbox' => $mailbox,
                ]);

                continue;
            }

            $provider = $this->gmailProvider->forMailbox($mailbox);

            try {
                $stats = $this->syncMailbox($mailbox, $provider);
                $pulled += $stats['received'];
                $ingested += $stats['received'];
                $staleMessagesSkipped += $stats['stale_skipped'];
                $messagesFailed += $stats['fetch_failed'];
                $messagesRetried += $stats['retried'];
                $historyPages += $stats['pages'];
                $cursorAdvances += $stats['cursor_advances'];
                $this->gmailHealth->invalidateCachedHealth();
            } catch (Throwable $exception) {
                $failedMailboxes++;
                $provider->recordError($exception->getMessage());
                $this->gmailHealth->invalidateCachedHealth();

                Log::error('[GmailInbound] Sync failed for mailbox.', [
                    'mailbox' => $mailbox,
                    'exception' => $exception::class,
                    'message' => $this->safeErrorMessage($exception),
                ]);
            } finally {
                $lock->release();
            }
        }

        return [
            'mailboxes' => count($mailboxes),
            'pulled' => $pulled,
            'ingested' => $ingested,
            'skipped' => $skipped,
            'stale_messages_skipped' => $staleMessagesSkipped,
            'messages_failed' => $messagesFailed,
            'messages_retried' => $messagesRetried,
            'history_pages' => $historyPages,
            'cursor_advances' => $cursorAdvances,
            'failed_mailboxes' => $failedMailboxes,
        ];
    }

    public function rebaselineMailbox(string $mailbox): string
    {
        $this->assertGmailConfigured();

        $provider = $this->gmailProvider->forMailbox($mailbox);

        return $provider->rebaseline();
    }

    /**
     * @return array{
     *     received: int,
     *     linked: int,
     *     historical: int,
     *     ignored: int,
     *     failed: int,
     *     stale_skipped: int,
     *     fetch_failed: int,
     *     retried: int,
     *     pages: int,
     *     cursor_advances: int
     * }
     */
    private function syncMailbox(string $mailbox, GmailInboundEmailProvider $provider): array
    {
        $startedAt = microtime(true);
        $previousHistoryId = GmailMailboxSyncState::query()
            ->where('mailbox', $mailbox)
            ->value('history_id');

        $linked = 0;
        $historical = 0;
        $ignored = 0;
        $failed = 0;
        $received = 0;

        $pullStats = $provider->pullIncremental(function (array $messages) use (
            &$linked,
            &$historical,
            &$ignored,
            &$failed,
            &$received,
            $mailbox,
        ): void {
            $messages = $this->oldestFirst($messages);

            foreach ($messages as $dto) {
                $result = $this->ingestService->ingest($dto);
                $received++;
                $this->metrics->incrementToday($mailbox, 'processed');

                if ($result === null) {
                    continue;
                }

                $status = $result->fresh()?->status ?? $result->status;

                match ($status) {
                    IncomingEmailMessageStatus::Linked => $linked++,
                    IncomingEmailMessageStatus::HistoricalCustomer => $historical++,
                    IncomingEmailMessageStatus::Ignored => $ignored++,
                    IncomingEmailMessageStatus::Failed => $failed++,
                    default => null,
                };
            }
        });

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $newHistoryId = GmailMailboxSyncState::query()->where('mailbox', $mailbox)->value('history_id');

        $provider->recordRunMetrics(
            processed: $received,
            skipped: $pullStats['stale_skipped'] + $pullStats['fetch_failed'],
            retried: $pullStats['retried'],
            failed: $pullStats['fetch_failed'],
            pages: $pullStats['pages'],
            cursorAdvances: $pullStats['cursor_advances'],
            durationMs: $durationMs,
            latencyMs: $pullStats['last_latency_ms'],
        );

        Log::info('[GmailInbound] Mailbox sync completed.', [
            'mailbox' => $mailbox,
            'previous_history_id' => $previousHistoryId,
            'new_history_id' => $newHistoryId,
            'messages_received' => $received,
            'stale_messages_skipped' => $pullStats['stale_skipped'],
            'messages_failed' => $pullStats['fetch_failed'],
            'messages_retried' => $pullStats['retried'],
            'history_pages' => $pullStats['pages'],
            'cursor_advances' => $pullStats['cursor_advances'],
            'linked' => $linked,
            'historical' => $historical,
            'ignored' => $ignored,
            'failed' => $failed,
            'elapsed_ms' => $durationMs,
        ]);

        return [
            'received' => $received,
            'linked' => $linked,
            'historical' => $historical,
            'ignored' => $ignored,
            'failed' => $failed,
            'stale_skipped' => $pullStats['stale_skipped'],
            'fetch_failed' => $pullStats['fetch_failed'],
            'retried' => $pullStats['retried'],
            'pages' => $pullStats['pages'],
            'cursor_advances' => $pullStats['cursor_advances'],
        ];
    }

    /**
     * @param  list<NormalizedInboundEmail>  $messages
     * @return list<NormalizedInboundEmail>
     */
    private function oldestFirst(array $messages): array
    {
        $indexed = [];

        foreach ($messages as $index => $message) {
            $indexed[] = [$message, $index];
        }

        usort($indexed, static function (array $left, array $right): int {
            $timeCompare = $left[0]->receivedAt->getTimestamp() <=> $right[0]->receivedAt->getTimestamp();

            return $timeCompare !== 0 ? $timeCompare : $left[1] <=> $right[1];
        });

        return array_map(static fn (array $row): NormalizedInboundEmail => $row[0], $indexed);
    }

    private function assertGmailConfigured(): void
    {
        if ($this->syncMailboxes() === []) {
            throw new RuntimeException(
                'Inbound email Gmail sync has no mailboxes configured. Set INBOUND_EMAIL_GMAIL_MAILBOXES or inbound_email.mailboxes.'
            );
        }

        $credentials = trim((string) config('inbound_email.gmail.service_account_json', ''));

        if ($credentials === '') {
            throw new RuntimeException(
                'GOOGLE_SERVICE_ACCOUNT_JSON is not configured. Provide a service-account JSON file path or inline JSON.'
            );
        }

        $looksLikeJson = str_starts_with(ltrim($credentials), '{');

        if (! $looksLikeJson && ! is_file($credentials)) {
            throw new RuntimeException(
                'GOOGLE_SERVICE_ACCOUNT_JSON file not found: '.$credentials
            );
        }
    }

    /**
     * @return list<string>
     */
    private function syncMailboxes(): array
    {
        $configured = config('inbound_email.gmail.sync_mailboxes', []);

        if (is_array($configured) && $configured !== []) {
            return array_values(array_unique(array_map(
                static fn (mixed $mailbox): string => strtolower(trim((string) $mailbox)),
                $configured,
            )));
        }

        $fromMap = array_keys(config('inbound_email.mailboxes', []));

        return array_values(array_unique(array_map(
            static fn (mixed $mailbox): string => strtolower(trim((string) $mailbox)),
            $fromMap,
        )));
    }

    private function mailboxLockKey(string $mailbox): string
    {
        return 'gmail-inbound-sync:'.sha1(strtolower(trim($mailbox)));
    }

    private function safeErrorMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        $message = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', '[redacted-jwt]', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }
}
