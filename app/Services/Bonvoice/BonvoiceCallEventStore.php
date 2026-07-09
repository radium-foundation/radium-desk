<?php

namespace App\Services\Bonvoice;

use App\Models\BonvoiceCallEvent;
use App\Models\BonvoiceWebhookLog;
use App\Services\Interakt\InteraktCustomerMatcher;
use RuntimeException;

class BonvoiceCallEventStore
{
    public function __construct(
        private readonly BonvoiceWebhookPayloadParser $payloadParser,
        private readonly InteraktCustomerMatcher $customerMatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertFromWebhook(array $payload, int $webhookLogId): BonvoiceCallEvent
    {
        $callId = $this->payloadParser->callId($payload);
        $leg = $this->payloadParser->leg($payload);

        if (! filled($callId)) {
            throw new RuntimeException('BonVoice webhook payload is missing callID.');
        }

        $this->assertAccountIdMatches($payload);

        $customerPhoneNumber = $this->payloadParser->customerPhoneNumber($payload);
        $storedPhone = $this->customerMatcher->resolveStoredPhone(
            phoneNumber: $customerPhoneNumber,
        );

        $existing = BonvoiceCallEvent::query()
            ->where('call_id', $callId)
            ->where('leg', $leg)
            ->first();

        return BonvoiceCallEvent::query()->updateOrCreate(
            [
                'call_id' => $callId,
                'leg' => $leg,
            ],
            [
                'customer_phone' => $storedPhone ?? $existing?->customer_phone ?? $customerPhoneNumber,
                'source_number' => $this->payloadParser->sourceNumber($payload) ?? $existing?->source_number,
                'destination_number' => $this->payloadParser->destinationNumber($payload) ?? $existing?->destination_number,
                'display_number' => $this->payloadParser->displayNumber($payload) ?? $existing?->display_number,
                'direction' => $this->payloadParser->direction($payload) ?? $existing?->direction,
                'status' => $this->payloadParser->status($payload) ?? $existing?->status,
                'agent_status' => $this->payloadParser->agentStatus($payload) ?? $existing?->agent_status,
                'call_type' => $this->payloadParser->callType($payload) ?? $existing?->call_type,
                'account_id' => $this->payloadParser->accountId($payload) ?? $existing?->account_id,
                'data_source' => $this->payloadParser->dataSource($payload) ?? $existing?->data_source,
                'event_id' => $this->payloadParser->eventId($payload) ?? $existing?->event_id,
                'callback_parent_id' => $this->payloadParser->callbackParentId($payload) ?? $existing?->callback_parent_id,
                'callback_params' => $this->payloadParser->callbackParams($payload) ?? $existing?->callback_params,
                'started_at' => $this->payloadParser->startedAt($payload) ?? $existing?->started_at,
                'recording_url' => $this->payloadParser->recordingUrl($payload) ?? $existing?->recording_url,
                'payload' => $payload,
                'webhook_log_id' => $webhookLogId,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertAccountIdMatches(array $payload): void
    {
        $configuredAccountId = (string) config('bonvoice.account_id');

        if ($configuredAccountId === '') {
            return;
        }

        $payloadAccountId = $this->payloadParser->accountId($payload);

        if (config('bonvoice.verify_webhook_auth')) {
            if ($payloadAccountId === null) {
                throw new RuntimeException(BonvoiceWebhookAuthVerifier::ERROR_MISSING_ACCOUNT_ID);
            }

            if ($payloadAccountId !== $configuredAccountId) {
                throw new RuntimeException(BonvoiceWebhookAuthVerifier::ERROR_INVALID_ACCOUNT_ID);
            }

            return;
        }

        if ($payloadAccountId !== null && $payloadAccountId !== $configuredAccountId) {
            throw new RuntimeException(BonvoiceWebhookAuthVerifier::ERROR_INVALID_ACCOUNT_ID);
        }
    }
}
