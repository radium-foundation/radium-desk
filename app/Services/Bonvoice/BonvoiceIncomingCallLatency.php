<?php

namespace App\Services\Bonvoice;

use App\Models\BonvoiceWebhookLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Observe-only stage timing for the IVR incoming-call popup path.
 * Never throws into the webhook / notify / broadcast path.
 */
class BonvoiceIncomingCallLatency
{
    public const STAGE_PERSIST = 'S1_persist';

    public const STAGE_OUTBOX = 'S2_outbox';

    public const STAGE_PROCESS = 'S3_process';

    public const STAGE_RESOLVE = 'S4_resolve';

    public const STAGE_BROADCAST = 'S5_broadcast';

    public const STAGE_SIDE_PATHS = 'S6_side_paths';

    public const STAGE_REQUEST = 'S0_request';

    private ?float $startedAt = null;

    private ?int $webhookLogId = null;

    private ?string $callId = null;

    public function enabled(): bool
    {
        return (bool) config('bonvoice.incoming_latency_log', true);
    }

    public function hasBegun(): bool
    {
        return $this->startedAt !== null;
    }

    public function begin(int $webhookLogId, ?Carbon $receivedAt = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $this->webhookLogId = $webhookLogId;
            $this->startedAt = $receivedAt !== null
                ? $this->carbonToMicrotime($receivedAt)
                : microtime(true);
        } catch (Throwable) {
            $this->reset();
        }
    }

    public function beginFromWebhookLog(BonvoiceWebhookLog $webhookLog): void
    {
        $receivedAt = $webhookLog->received_at;

        $this->begin(
            $webhookLog->id,
            $receivedAt instanceof Carbon ? $receivedAt : ($receivedAt !== null ? Carbon::parse($receivedAt) : null),
        );
    }

    public function setCallId(?string $callId): void
    {
        if ($callId === null || $callId === '') {
            return;
        }

        $this->callId = $callId;
    }

    public function webhookLogId(): ?int
    {
        return $this->webhookLogId;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  array<string, mixed>  $context
     * @return T
     */
    public function measure(string $stage, callable $callback, array $context = []): mixed
    {
        if (! $this->enabled() || ! $this->hasBegun()) {
            return $callback();
        }

        $stageStartedAt = microtime(true);

        try {
            return $callback();
        } finally {
            $this->logStage(
                stage: $stage,
                durationMs: (microtime(true) - $stageStartedAt) * 1000,
                context: $context,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function mark(string $stage, ?float $durationMs = null, array $context = []): void
    {
        if (! $this->enabled() || ! $this->hasBegun()) {
            return;
        }

        $this->logStage($stage, $durationMs, $context);
    }

    public function reset(): void
    {
        $this->startedAt = null;
        $this->webhookLogId = null;
        $this->callId = null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logStage(string $stage, ?float $durationMs, array $context): void
    {
        try {
            $payload = array_filter([
                'stage' => $stage,
                'duration_ms' => $durationMs !== null ? (int) round($durationMs) : null,
                'total_ms' => (int) round((microtime(true) - ($this->startedAt ?? microtime(true))) * 1000),
                'webhook_log_id' => $this->webhookLogId,
                'call_id' => $this->callId,
                ...$context,
            ], static fn ($value) => $value !== null);

            Log::info('[BonVoice Incoming Latency]', $payload);
        } catch (Throwable) {
            // Observe-only: never disrupt the call path.
        }
    }

    private function carbonToMicrotime(Carbon $time): float
    {
        return (float) $time->getTimestamp() + ((float) $time->micro / 1_000_000);
    }
}
