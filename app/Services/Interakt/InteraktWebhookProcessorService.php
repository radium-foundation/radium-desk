<?php

namespace App\Services\Interakt;

use App\Models\InteraktWebhookLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InteraktWebhookProcessorService
{
    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly InteraktWebhookPayloadParser $payloadParser,
        private readonly InteraktMessageStore $messageStore,
        private readonly WhatsAppCommunicationSummaryStore $summaryStore,
    ) {}

    public function process(InteraktWebhookLog $webhookLog): InteraktWebhookLog
    {
        $payload = $webhookLog->payload ?? [];

        try {
            DB::transaction(function () use ($webhookLog, $payload): void {
                if (! $this->payloadParser->shouldPersistMessage($payload)) {
                    $this->markProcessed($webhookLog);

                    return;
                }

                $message = $this->messageStore->upsertFromWebhook($payload);

                if ($message === null) {
                    throw new RuntimeException('Interakt webhook payload is missing message id.');
                }

                $this->summaryStore->refreshForMessage($message, $payload);

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

    private function markProcessed(InteraktWebhookLog $webhookLog): void
    {
        $webhookLog->update([
            'processing_status' => self::STATUS_PROCESSED,
            'processing_error' => null,
            'processed_at' => now(),
        ]);
    }
}
