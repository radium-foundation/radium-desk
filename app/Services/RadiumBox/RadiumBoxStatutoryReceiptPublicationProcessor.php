<?php

namespace App\Services\RadiumBox;

use App\Enums\RadiumBox\RadiumBoxStatutoryReceiptPublicationStatus;
use App\Models\OutboxEvent;
use App\Models\RadiumBoxStatutoryReceiptPublication;
use App\Models\StatutoryInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RadiumBoxStatutoryReceiptPublicationProcessor
{
    public function __construct(
        private readonly RadiumBoxStatutoryReceiptPublicationClient $client,
        private readonly RadiumBoxStatutoryReceiptPublicationPolicy $publicationPolicy,
    ) {}

    public function process(OutboxEvent $event): void
    {
        $invoiceId = (int) ($event->payload['statutory_invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            throw new RadiumBoxStatutoryReceiptPublicationPermanentFailureException(
                'RadiumBox receipt outbox event is missing statutory_invoice_id.',
            );
        }

        $invoice = StatutoryInvoice::query()->find($invoiceId);
        if ($invoice === null) {
            throw new RadiumBoxStatutoryReceiptPublicationPermanentFailureException(
                'Statutory invoice not found: '.$invoiceId,
            );
        }

        if (! (new RadiumBoxStatutoryReceiptPublicationOutboxWriter)->shouldSchedule($invoice)) {
            return;
        }

        $publication = DB::transaction(function () use ($invoice, $event): RadiumBoxStatutoryReceiptPublication {
            $publication = RadiumBoxStatutoryReceiptPublication::query()
                ->where('statutory_invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();

            if ($publication === null) {
                throw new RadiumBoxStatutoryReceiptPublicationPermanentFailureException(
                    'Publication row missing for statutory invoice '.$invoice->id,
                );
            }

            if ($publication->isTerminalSuccess()) {
                return $publication;
            }

            $publication->forceFill([
                'attempt_count' => $publication->attempt_count + 1,
                'last_outbox_event_id' => $event->id,
            ])->save();

            return $publication->fresh() ?? $publication;
        });

        if ($publication->isTerminalSuccess()) {
            return;
        }

        $deliveryBlockedReason = $this->publicationPolicy->deliveryBlockedReason($invoice);
        if ($deliveryBlockedReason !== null) {
            $publication->update([
                'status' => RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation,
                'last_error' => $deliveryBlockedReason,
            ]);

            return;
        }

        if (in_array($publication->status, [
            RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation,
            RadiumBoxStatutoryReceiptPublicationStatus::FailedPermanent,
        ], true)) {
            $publication->update([
                'status' => RadiumBoxStatutoryReceiptPublicationStatus::Pending,
                'last_error' => null,
            ]);
            $publication = $publication->fresh() ?? $publication;
        }

        try {
            $result = $this->client->publish($invoice, $publication->http_idempotency_key);
        } catch (RadiumBoxStatutoryReceiptPublicationRetryableException $exception) {
            throw $exception;
        } catch (RadiumBoxStatutoryReceiptPublicationPermanentFailureException $exception) {
            $this->markPermanentFailure($publication, $exception->getMessage());

            throw $exception;
        }

        if ($result->retryable) {
            throw new RadiumBoxStatutoryReceiptPublicationRetryableException(
                $result->message ?? 'Retryable RadiumBox statutory receipt response.',
            );
        }

        if ($result->boxReceiptId !== null) {
            $status = $result->duplicateDelivery
                ? RadiumBoxStatutoryReceiptPublicationStatus::DuplicateDelivered
                : RadiumBoxStatutoryReceiptPublicationStatus::Delivered;

            $publication->update([
                'status' => $status,
                'box_receipt_id' => $result->boxReceiptId,
                'last_http_status' => $result->httpStatus,
                'duplicate_delivery' => $result->duplicateDelivery,
                'eligibility_code' => $result->eligibilityCode,
                'last_error' => null,
                'delivered_at' => now(),
            ]);

            Log::info('[RadiumBoxStatutoryReceipt] Publication delivered.', [
                'statutory_invoice_id' => $invoice->id,
                'http_status' => $result->httpStatus,
                'duplicate_delivery' => $result->duplicateDelivery,
            ]);

            return;
        }

        $this->markPermanentFailure(
            $publication,
            $result->message ?? 'Permanent RadiumBox statutory receipt failure.',
            $result->httpStatus,
            $result->eligibilityCode,
        );

        throw new RadiumBoxStatutoryReceiptPublicationPermanentFailureException(
            $result->message ?? 'RadiumBox statutory receipt publication failed.',
        );
    }

    private function markPermanentFailure(
        RadiumBoxStatutoryReceiptPublication $publication,
        string $message,
        ?int $httpStatus = null,
        ?string $eligibilityCode = null,
    ): void {
        $publication->update([
            'status' => RadiumBoxStatutoryReceiptPublicationStatus::FailedPermanent,
            'last_http_status' => $httpStatus,
            'eligibility_code' => $eligibilityCode,
            'last_error' => mb_substr($message, 0, 512),
        ]);
    }
}
