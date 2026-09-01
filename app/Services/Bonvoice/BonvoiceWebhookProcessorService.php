<?php

namespace App\Services\Bonvoice;

use App\Models\BonvoiceCallEvent;
use App\Models\BonvoiceWebhookLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BonvoiceWebhookProcessorService
{
    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly BonvoiceWebhookPayloadParser $payloadParser,
        private readonly BonvoiceCallEventStore $callEventStore,
        private readonly BonvoiceLiveCallAssistService $liveCallAssistService,
        private readonly BonvoiceMissedCallRecoveryService $missedCallRecoveryService,
        private readonly BonvoiceIncomingCallLatency $incomingCallLatency,
    ) {}

    public function process(
        BonvoiceWebhookLog $webhookLog,
        ?BonvoiceWebhookProcessOptions $options = null,
    ): BonvoiceWebhookLog {
        $options ??= new BonvoiceWebhookProcessOptions;
        $payload = $webhookLog->payload ?? [];

        try {
            if ($this->payloadParser->hasRequiredIdentifiers($payload)) {
                $this->incomingCallLatency->setCallId($this->payloadParser->callId($payload));
            }

            $previousStatus = null;
            $previousCallType = null;

            $callEvent = $this->incomingCallLatency->measure(
                BonvoiceIncomingCallLatency::STAGE_PROCESS,
                function () use ($webhookLog, $payload, &$previousStatus, &$previousCallType): BonvoiceCallEvent {
                    return $this->persistCallEvent(
                        $webhookLog,
                        $payload,
                        $previousStatus,
                        $previousCallType,
                    );
                },
            );

            if (! $options->suppressNotifications) {
                $this->liveCallAssistService->maybeNotify($callEvent);
                $this->liveCallAssistService->maybeBroadcastAnsweredAutoOpen(
                    $callEvent,
                    $previousStatus,
                    $previousCallType,
                );
                $this->liveCallAssistService->maybeBroadcastMissedPopupDismiss(
                    $callEvent,
                    $previousStatus,
                    $previousCallType,
                );
            }

            if (! $options->suppressRecovery) {
                $this->missedCallRecoveryService->process($callEvent, $previousStatus, $previousCallType);
            }

            return $webhookLog->fresh();
        } catch (\Throwable $exception) {
            $webhookLog->update([
                'processing_status' => self::STATUS_FAILED,
                'processing_error' => $exception->getMessage(),
                'processed_at' => now(),
            ]);

            throw $exception;
        }
    }

    /**
     * Persist one call_id+leg row in a new transaction per attempt.
     * MariaDB 1020 cannot be recovered inside the failed REPEATABLE READ
     * snapshot; Laravel may also wrap it as DeadlockException when this
     * unit of work is nested inside another transaction.
     *
     * @param  array<string, mixed>  $payload
     */
    private function persistCallEvent(
        BonvoiceWebhookLog $webhookLog,
        array $payload,
        ?string &$previousStatus,
        ?string &$previousCallType,
    ): BonvoiceCallEvent {
        $maxAttempts = max(1, (int) config('bonvoice.call_event_write_retry.max_attempts', 5));
        $sleepMilliseconds = max(0, (int) config('bonvoice.call_event_write_retry.sleep_milliseconds', 25));
        $attempt = 0;

        while (true) {
            $attempt++;

            if ($this->payloadParser->hasRequiredIdentifiers($payload)) {
                $previous = BonvoiceCallEvent::query()
                    ->where('call_id', $this->payloadParser->callId($payload))
                    ->where('leg', $this->payloadParser->leg($payload))
                    ->first(['status', 'call_type']);

                $previousStatus = $previous?->status;
                $previousCallType = $previous?->call_type;
            }

            try {
                return DB::transaction(function () use ($webhookLog, $payload): BonvoiceCallEvent {
                    if (! $this->payloadParser->hasRequiredIdentifiers($payload)) {
                        throw new \RuntimeException('BonVoice webhook payload is missing callID.');
                    }

                    $callEvent = $this->callEventStore->upsertFromWebhook($payload, $webhookLog->id);
                    $this->markProcessed($webhookLog);

                    return $callEvent;
                });
            } catch (Throwable $exception) {
                if (! BonvoiceCallEventWriteContention::isRetryable($exception) || $attempt >= $maxAttempts) {
                    throw $exception;
                }

                Log::warning('[BonVoice Webhook] Retrying call event persistence after DB contention.', [
                    'webhook_log_id' => $webhookLog->id,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error_code' => $exception instanceof QueryException
                        ? ($exception->errorInfo[1] ?? null)
                        : null,
                    'message' => $exception->getMessage(),
                ]);

                if ($sleepMilliseconds > 0) {
                    usleep($sleepMilliseconds * 1000 * $attempt);
                }
            }
        }
    }

    private function markProcessed(BonvoiceWebhookLog $webhookLog): void
    {
        $webhookLog->update([
            'processing_status' => self::STATUS_PROCESSED,
            'processing_error' => null,
            'processed_at' => now(),
        ]);
    }
}
