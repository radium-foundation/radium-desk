<?php

namespace App\Services\Bonvoice;

use App\Models\BonvoiceCallEvent;
use App\Models\BonvoiceWebhookLog;
use Illuminate\Support\Facades\DB;

class BonvoiceWebhookProcessorService
{
    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly BonvoiceWebhookPayloadParser $payloadParser,
        private readonly BonvoiceCallEventStore $callEventStore,
        private readonly BonvoiceLiveCallAssistService $liveCallAssistService,
        private readonly BonvoiceMissedCallRecoveryService $missedCallRecoveryService,
    ) {}

    public function process(
        BonvoiceWebhookLog $webhookLog,
        ?BonvoiceWebhookProcessOptions $options = null,
    ): BonvoiceWebhookLog {
        $options ??= new BonvoiceWebhookProcessOptions;
        $payload = $webhookLog->payload ?? [];

        try {
            $previousStatus = null;

            if ($this->payloadParser->hasRequiredIdentifiers($payload)) {
                $previousStatus = BonvoiceCallEvent::query()
                    ->where('call_id', $this->payloadParser->callId($payload))
                    ->where('leg', $this->payloadParser->leg($payload))
                    ->value('status');
            }

            $callEvent = DB::transaction(function () use ($webhookLog, $payload): BonvoiceCallEvent {
                if (! $this->payloadParser->hasRequiredIdentifiers($payload)) {
                    throw new \RuntimeException('BonVoice webhook payload is missing callID.');
                }

                $callEvent = $this->callEventStore->upsertFromWebhook($payload, $webhookLog->id);
                $this->markProcessed($webhookLog);

                return $callEvent;
            });

            if (! $options->suppressNotifications) {
                $this->liveCallAssistService->maybeNotify($callEvent);
                $this->liveCallAssistService->maybeBroadcastAnsweredAutoOpen($callEvent, $previousStatus);
            }

            if (! $options->suppressRecovery) {
                $this->missedCallRecoveryService->process($callEvent, $previousStatus);
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

    private function markProcessed(BonvoiceWebhookLog $webhookLog): void
    {
        $webhookLog->update([
            'processing_status' => self::STATUS_PROCESSED,
            'processing_error' => null,
            'processed_at' => now(),
        ]);
    }
}
