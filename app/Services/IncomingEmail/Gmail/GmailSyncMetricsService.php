<?php

namespace App\Services\IncomingEmail\Gmail;

use App\Models\GmailSyncMessageFailure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GmailSyncMetricsService
{
    public function recordFailure(
        string $mailbox,
        string $messageId,
        ?string $endpoint,
        ?int $httpStatus,
        ?array $errorPayload,
        ?string $historyId,
        int $attemptCount = 1,
        ?int $elapsedMs = null,
        ?string $requestId = null,
    ): void {
        GmailSyncMessageFailure::query()->create([
            'mailbox' => $mailbox,
            'message_id' => $messageId,
            'endpoint' => $endpoint,
            'http_status' => $httpStatus,
            'error_payload' => $errorPayload,
            'history_id' => $historyId,
            'attempt_count' => $attemptCount,
            'elapsed_ms' => $elapsedMs,
            'request_id' => $requestId,
        ]);

        $this->incrementToday($mailbox, 'failed');
    }

    public function incrementToday(string $mailbox, string $metric, int $by = 1): void
    {
        $key = $this->dailyKey($mailbox, $metric);
        $ttl = now()->endOfDay()->diffInSeconds(now()) + 60;

        if (! Cache::has($key)) {
            Cache::put($key, 0, $ttl);
        }

        Cache::increment($key, $by);
    }

    public function todayCount(string $mailbox, string $metric): int
    {
        return (int) Cache::get($this->dailyKey($mailbox, $metric), 0);
    }

    /**
     * @return array{
     *     processed: int,
     *     failed: int,
     *     skipped: int,
     *     retried: int,
     *     cursor_advances: int
     * }
     */
    public function todayTotals(string $mailbox): array
    {
        return [
            'processed' => $this->todayCount($mailbox, 'processed'),
            'failed' => $this->todayCount($mailbox, 'failed'),
            'skipped' => $this->todayCount($mailbox, 'skipped'),
            'retried' => $this->todayCount($mailbox, 'retried'),
            'cursor_advances' => $this->todayCount($mailbox, 'cursor_advances'),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function logFailure(string $message, array $context): void
    {
        Log::error('[GmailInbound] '.$message, $context);
    }

    private function dailyKey(string $mailbox, string $metric): string
    {
        return sprintf(
            'gmail.sync.metrics.%s.%s.%s',
            now()->toDateString(),
            sha1(strtolower(trim($mailbox))),
            $metric,
        );
    }
}
