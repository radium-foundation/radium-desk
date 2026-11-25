<?php

namespace App\Services\Cashfree;

use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Request-scoped Cashfree webhook stage timings (POST /api/webhooks/cashfree only).
 *
 * Emits redacted structured logs; inactive until {@see activate()}.
 */
final class CashfreeWebhookStageTiming
{
    public const LOG_EVENT = 'cashfree.webhook.stage_timing';

    private bool $active = false;

    private ?string $requestCorrelationId = null;

    private ?int $webhookLogId = null;

    private int $requestEntryHr = 0;

    private int $lastStageHr = 0;

    private ?int $transactionPersistenceStartHr = null;

    private ?int $transactionCommitStartHr = null;

    private int $persistRetryCount = 0;

    public function activate(): void
    {
        if ($this->active) {
            return;
        }

        $this->active = true;
        $this->requestCorrelationId = (string) Str::uuid();
        $this->requestEntryHr = hrtime(true);
        $this->lastStageHr = $this->requestEntryHr;
        $this->persistRetryCount = 0;

        $this->emitStage('request_entry', 'ok', 0.0);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function associateWebhookLogId(int $webhookLogId): void
    {
        if (! $this->active) {
            return;
        }

        $this->webhookLogId = $webhookLogId;
    }

    public function completeStage(string $stage, string $outcome, array $extra = []): void
    {
        if (! $this->active) {
            return;
        }

        $now = hrtime(true);
        $durationMs = ($now - $this->lastStageHr) / 1e6;
        $this->lastStageHr = $now;

        $this->emitStage($stage, $outcome, $durationMs, $extra);
    }

    /**
     * @param  callable(): mixed  $callback
     */
    public function measureStage(string $stage, callable $callback, string $outcomeOnSuccess = 'ok'): mixed
    {
        if (! $this->active) {
            return $callback();
        }

        $start = hrtime(true);

        try {
            $result = $callback();
            $durationMs = (hrtime(true) - $start) / 1e6;
            $this->lastStageHr = hrtime(true);
            $this->emitStage($stage, $outcomeOnSuccess, $durationMs);

            return $result;
        } catch (Throwable $throwable) {
            $durationMs = (hrtime(true) - $start) / 1e6;
            $this->lastStageHr = hrtime(true);
            $this->emitStage($stage, 'error', $durationMs, [
                'error_category' => 'stage_exception',
            ]);

            throw $throwable;
        }
    }

    public function recordPersistRetryAttempt(int $attempt, string $errorCategory): void
    {
        if (! $this->active) {
            return;
        }

        $this->persistRetryCount = max($this->persistRetryCount, $attempt);
        $this->emitStage('persist_attempt', 'retry', 0.0, [
            'retry_attempt' => $attempt,
            'retry_count' => $this->persistRetryCount,
            'error_category' => $errorCategory,
        ]);
    }

    public function recordPersistAttemptSuccess(int $attempt): void
    {
        if (! $this->active) {
            return;
        }

        $extra = [];
        if ($attempt > 1 || $this->persistRetryCount > 0) {
            $extra = [
                'retry_attempt' => $attempt,
                'retry_count' => max($this->persistRetryCount, $attempt),
            ];
        }

        $this->completeStage('persist_attempt', 'ok', $extra);
    }

    public function beginTransactionPersistence(): void
    {
        if (! $this->active) {
            return;
        }

        $this->transactionPersistenceStartHr = hrtime(true);
        $this->transactionCommitStartHr = null;
    }

    public function markTransactionPersistenceComplete(string $outcome = 'ok'): void
    {
        if (! $this->active || $this->transactionPersistenceStartHr === null) {
            return;
        }

        $now = hrtime(true);
        $durationMs = ($now - $this->transactionPersistenceStartHr) / 1e6;
        $this->transactionPersistenceStartHr = null;
        $this->lastStageHr = $now;
        $this->emitStage('transaction_persistence', $outcome, $durationMs);

        if ($outcome === 'ok') {
            $this->transactionCommitStartHr = $now;
        }
    }

    public function markTransactionCommitComplete(): void
    {
        if (! $this->active || $this->transactionCommitStartHr === null) {
            return;
        }

        $now = hrtime(true);
        $durationMs = ($now - $this->transactionCommitStartHr) / 1e6;
        $this->transactionCommitStartHr = null;
        $this->lastStageHr = $now;
        $this->emitStage('transaction_commit', 'ok', $durationMs);
    }

    /**
     * @param  callable(): void  $dispatch
     */
    public function measureOrderPaidDispatch(callable $dispatch): void
    {
        if (! $this->active) {
            $dispatch();

            return;
        }

        $listenerEnqueueCount = 0;
        $enqueueHr = hrtime(true);

        $listener = function (JobQueued $event) use (&$listenerEnqueueCount): void {
            $listenerEnqueueCount++;
        };

        Event::listen(JobQueued::class, $listener);

        $dispatchStart = hrtime(true);

        try {
            $dispatch();
        } finally {
            Event::forget(JobQueued::class);
        }

        $dispatchMs = (hrtime(true) - $dispatchStart) / 1e6;
        $enqueueMs = (hrtime(true) - $enqueueHr) / 1e6;
        $this->lastStageHr = hrtime(true);

        $this->emitStage('order_paid_dispatch', 'ok', $dispatchMs, [
            'listener_enqueue_count' => $listenerEnqueueCount,
        ]);
        $this->emitStage('redis_enqueue', 'ok', $enqueueMs, [
            'listener_enqueue_count' => $listenerEnqueueCount,
        ]);
    }

    public function responseHandoff(int $httpStatus, string $outcome): void
    {
        if (! $this->active) {
            return;
        }

        $totalMs = (hrtime(true) - $this->requestEntryHr) / 1e6;
        $this->lastStageHr = hrtime(true);
        $this->emitStage('response_handoff', $outcome, $totalMs, [
            'http_status' => $httpStatus,
        ]);
    }

    public static function classifyQueryException(QueryException $exception): string
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $previous = $exception->getPrevious();
        if ($previous instanceof \PDOException && isset($previous->errorInfo[1])) {
            $driverCode = (int) $previous->errorInfo[1];
        }

        if (in_array($driverCode, [1213, 1205], true)) {
            return 'db_contention';
        }

        if ($driverCode === 1062) {
            return 'duplicate_key';
        }

        if ($driverCode === 1020) {
            return 'record_changed';
        }

        $message = $exception->getMessage();

        if (str_contains($message, '1213')
            || str_contains($message, 'Deadlock')
            || str_contains($message, '1205')
            || str_contains($message, 'Lock wait timeout')) {
            return 'db_contention';
        }

        return 'query_exception';
    }

    /**
     * @param  array<string, int|float|string|null>  $extra
     */
    private function emitStage(string $stage, string $outcome, float $durationMs, array $extra = []): void
    {
        $payload = [
            'event' => self::LOG_EVENT,
            'request_correlation_id' => $this->requestCorrelationId,
            'webhook_log_id' => $this->webhookLogId,
            'stage' => $stage,
            'duration_ms' => round($durationMs, 2),
            'outcome' => $outcome,
        ];

        foreach ($extra as $key => $value) {
            if (! in_array($key, [
                'retry_attempt',
                'retry_count',
                'listener_enqueue_count',
                'http_status',
                'error_category',
            ], true)) {
                continue;
            }

            if ($key === 'duration_ms' && isset($payload['duration_ms'])) {
                $payload['duration_ms'] = round((float) $value, 2);

                continue;
            }

            $payload[$key] = $value;
        }

        Log::info(self::LOG_EVENT, $payload);
    }
}
