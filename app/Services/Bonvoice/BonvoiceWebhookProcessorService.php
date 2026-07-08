<?php

namespace App\Services\Bonvoice;

use App\Models\BonvoiceWebhookLog;
use Illuminate\Support\Facades\DB;

class BonvoiceWebhookProcessorService
{
    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly BonvoiceWebhookPayloadParser $payloadParser,
        private readonly BonvoiceCallEventStore $callEventStore,
    ) {}

    public function process(BonvoiceWebhookLog $webhookLog): BonvoiceWebhookLog
    {
        $payload = $webhookLog->payload ?? [];

        try {
            DB::transaction(function () use ($webhookLog, $payload): void {
                if (! $this->payloadParser->hasRequiredIdentifiers($payload)) {
                    throw new \RuntimeException('BonVoice webhook payload is missing callID.');
                }

                $this->callEventStore->upsertFromWebhook($payload, $webhookLog->id);
                $this->markProcessed($webhookLog);
            });

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
