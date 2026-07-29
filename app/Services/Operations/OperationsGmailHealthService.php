<?php

namespace App\Services\Operations;

use App\Enums\OperationsHealthStatus;
use App\Models\GmailMailboxSyncState;
use App\Models\GmailSyncMessageFailure;
use App\Models\IncomingEmailMessage;
use App\Services\IncomingEmail\Gmail\GmailSyncMetricsService;
use App\Services\Platform\PlatformHealthCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationsGmailHealthService
{
    public function __construct(
        private readonly GmailSyncMetricsService $metrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function widget(): array
    {
        if (! config('inbound_email.enabled') || ! config('inbound_email.gmail.enabled')) {
            return $this->disabledWidget();
        }

        $mailboxes = $this->configuredMailboxes();

        if ($mailboxes === []) {
            return $this->notConfiguredWidget('No Gmail sync mailboxes configured.');
        }

        if (! Schema::hasTable('gmail_mailbox_sync_states')) {
            return $this->notConfiguredWidget('Gmail sync state table unavailable.');
        }

        $mailbox = $mailboxes[0];
        $state = GmailMailboxSyncState::query()->where('mailbox', $mailbox)->first();
        $today = $this->metrics->todayTotals($mailbox);
        $status = $this->resolveStatus($state, $today);
        $schedulerLastRun = PlatformHealthCache::schedulerLastRunAt();
        $schedulerRunning = $schedulerLastRun !== null && $schedulerLastRun->greaterThan(now()->subMinutes(5));
        $queueHealthy = $this->queueHealthy();
        $oauthStatus = $this->oauthStatus($state);
        $cursorLag = $state?->cursorLag();
        $processedToday = $this->messagesProcessedToday();
        $failedToday = max($today['failed'], $this->failuresToday($mailbox));

        return [
            'key' => 'gmail',
            'label' => 'Gmail',
            'enabled' => true,
            'status' => $status->value,
            'status_label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'detail' => $this->detail($status, $state, $failedToday, $cursorLag),
            'overall_status' => $status->label(),
            'last_successful_sync_at' => $state?->last_synced_at,
            'last_attempted_sync_at' => $state?->last_attempted_at ?? $state?->updated_at,
            'mailbox' => $mailbox,
            'history_cursor' => $state?->history_id,
            'profile_history_id' => $state?->profile_history_id,
            'cursor_lag' => $cursorLag,
            'messages_processed_today' => max($today['processed'], $processedToday),
            'messages_failed_today' => $failedToday,
            'messages_skipped_today' => $today['skipped'] + (int) ($state?->messages_skipped_last_run ?? 0),
            'retry_count_today' => $today['retried'],
            'last_error' => $state?->last_error,
            'response_latency_ms' => $state?->last_response_latency_ms,
            'oauth_status' => $oauthStatus,
            'scheduler_running' => $schedulerRunning,
            'scheduler_last_run_at' => $schedulerLastRun,
            'queue_healthy' => $queueHealthy,
            'api_quota' => null,
            'messages_processed_last_run' => (int) ($state?->messages_processed_last_run ?? 0),
            'messages_failed_last_run' => (int) ($state?->messages_failed_last_run ?? 0),
            'history_pages_last_run' => (int) ($state?->history_pages_last_run ?? 0),
            'cursor_advances_last_run' => (int) ($state?->cursor_advances_last_run ?? 0),
            'last_sync_duration_ms' => $state?->last_sync_duration_ms,
            'consecutive_failures' => (int) ($state?->consecutive_failures ?? 0),
            'recent_failures' => $this->recentFailures($mailbox),
            'sync_now_url' => route('admin.gmail.sync-now'),
            'rebaseline_url' => route('admin.gmail.rebaseline'),
            'logs_url' => route('admin.gmail.logs'),
            'failed_messages_url' => route('admin.gmail.failed-messages'),
        ];
    }

    /**
     * @return array{key: string, label: string, status: string, status_label: string, badge_class: string, last_success_at: mixed, detail: string, retry_count: int}
     */
    public function card(): array
    {
        $widget = $this->widget();

        return [
            'key' => 'gmail',
            'label' => 'Gmail',
            'status' => $widget['status'],
            'status_label' => $widget['status_label'],
            'badge_class' => $widget['badge_class'],
            'last_success_at' => $widget['last_successful_sync_at'],
            'detail' => $widget['detail'],
            'retry_count' => (int) ($widget['retry_count_today'] ?? 0),
        ];
    }

    /**
     * @param  array{failed: int, skipped: int, processed: int, retried: int, cursor_advances: int}  $today
     */
    public function resolveStatus(?GmailMailboxSyncState $state, array $today = []): OperationsHealthStatus
    {
        if (! config('inbound_email.enabled') || ! config('inbound_email.gmail.enabled')) {
            return OperationsHealthStatus::Disabled;
        }

        if ($this->configuredMailboxes() === []) {
            return OperationsHealthStatus::NotConfigured;
        }

        if ($state === null) {
            return OperationsHealthStatus::Warning;
        }

        if (filled($state->last_error) || (int) $state->consecutive_failures >= 3) {
            return OperationsHealthStatus::Failed;
        }

        if (($today['failed'] ?? 0) > 0 || (int) $state->messages_failed_last_run > 0) {
            return OperationsHealthStatus::Warning;
        }

        if ($state->last_synced_at === null) {
            return OperationsHealthStatus::Warning;
        }

        if ($state->last_synced_at->lt(now()->subMinutes(15))) {
            return OperationsHealthStatus::Warning;
        }

        return OperationsHealthStatus::Healthy;
    }

    /**
     * @return list<string>
     */
    public function configuredMailboxes(): array
    {
        $configured = config('inbound_email.gmail.sync_mailboxes', []);

        if (is_array($configured) && $configured !== []) {
            return array_values(array_unique(array_map(
                static fn (mixed $mailbox): string => strtolower(trim((string) $mailbox)),
                $configured,
            )));
        }

        return array_values(array_unique(array_map(
            static fn (mixed $mailbox): string => strtolower(trim((string) $mailbox)),
            array_keys(config('inbound_email.mailboxes', [])),
        )));
    }

    private function detail(
        OperationsHealthStatus $status,
        ?GmailMailboxSyncState $state,
        int $failedToday,
        ?int $cursorLag,
    ): string {
        return match ($status) {
            OperationsHealthStatus::Healthy => 'Gmail inbound sync is healthy.',
            OperationsHealthStatus::Disabled => 'Gmail inbound sync is disabled.',
            OperationsHealthStatus::NotConfigured => 'Gmail inbound sync is not configured.',
            OperationsHealthStatus::Failed => filled($state?->last_error)
                ? 'Gmail sync error: '.mb_substr((string) $state->last_error, 0, 160)
                : 'Gmail sync is failing.',
            OperationsHealthStatus::Warning => $failedToday > 0
                ? sprintf('%d message fetch failure(s) today; sync continues.', $failedToday)
                : ($cursorLag !== null && $cursorLag > 1000
                    ? sprintf('Cursor lag is %s history ticks.', number_format($cursorLag))
                    : 'Gmail sync needs attention.'),
        };
    }

    private function oauthStatus(?GmailMailboxSyncState $state): string
    {
        if ($state?->oauth_status) {
            return (string) $state->oauth_status;
        }

        $credentials = trim((string) config('inbound_email.gmail.service_account_json', ''));

        if ($credentials === '') {
            return 'missing_credentials';
        }

        $looksLikeJson = str_starts_with(ltrim($credentials), '{');

        if (! $looksLikeJson && ! is_file($credentials)) {
            return 'missing_credentials';
        }

        return 'ok';
    }

    private function queueHealthy(): bool
    {
        if (! Schema::hasTable('jobs') || ! Schema::hasTable('failed_jobs')) {
            return true;
        }

        $failed = (int) DB::table('failed_jobs')->count();

        return $failed === 0;
    }

    private function messagesProcessedToday(): int
    {
        if (! Schema::hasTable('incoming_email_messages')) {
            return 0;
        }

        return IncomingEmailMessage::query()
            ->where('provider', 'gmail')
            ->whereDate('created_at', today())
            ->count();
    }

    private function failuresToday(string $mailbox): int
    {
        if (! Schema::hasTable('gmail_sync_message_failures')) {
            return 0;
        }

        return GmailSyncMessageFailure::query()
            ->where('mailbox', $mailbox)
            ->whereDate('created_at', today())
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentFailures(string $mailbox): array
    {
        if (! Schema::hasTable('gmail_sync_message_failures')) {
            return [];
        }

        return GmailSyncMessageFailure::query()
            ->where('mailbox', $mailbox)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(static fn (GmailSyncMessageFailure $failure): array => [
                'id' => $failure->id,
                'message_id' => $failure->message_id,
                'http_status' => $failure->http_status,
                'created_at' => $failure->created_at,
                'error' => is_array($failure->error_payload)
                    ? ($failure->error_payload['message'] ?? json_encode($failure->error_payload))
                    : null,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function disabledWidget(): array
    {
        return [
            'key' => 'gmail',
            'label' => 'Gmail',
            'enabled' => false,
            'status' => OperationsHealthStatus::Disabled->value,
            'status_label' => OperationsHealthStatus::Disabled->label(),
            'badge_class' => OperationsHealthStatus::Disabled->badgeClass(),
            'detail' => 'Gmail inbound sync is disabled.',
            'overall_status' => OperationsHealthStatus::Disabled->label(),
            'last_successful_sync_at' => null,
            'last_attempted_sync_at' => null,
            'mailbox' => null,
            'history_cursor' => null,
            'profile_history_id' => null,
            'cursor_lag' => null,
            'messages_processed_today' => 0,
            'messages_failed_today' => 0,
            'messages_skipped_today' => 0,
            'retry_count_today' => 0,
            'last_error' => null,
            'response_latency_ms' => null,
            'oauth_status' => 'disabled',
            'scheduler_running' => false,
            'scheduler_last_run_at' => null,
            'queue_healthy' => true,
            'api_quota' => null,
            'recent_failures' => [],
            'sync_now_url' => route('admin.gmail.sync-now'),
            'rebaseline_url' => route('admin.gmail.rebaseline'),
            'logs_url' => route('admin.gmail.logs'),
            'failed_messages_url' => route('admin.gmail.failed-messages'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notConfiguredWidget(string $detail): array
    {
        $widget = $this->disabledWidget();
        $widget['enabled'] = false;
        $widget['status'] = OperationsHealthStatus::NotConfigured->value;
        $widget['status_label'] = OperationsHealthStatus::NotConfigured->label();
        $widget['badge_class'] = OperationsHealthStatus::NotConfigured->badgeClass();
        $widget['detail'] = $detail;
        $widget['overall_status'] = OperationsHealthStatus::NotConfigured->label();
        $widget['oauth_status'] = 'not_configured';

        return $widget;
    }
}
