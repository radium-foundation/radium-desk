<?php

namespace App\Services\Interakt;

use App\Enums\InteraktDeliveryStatus;
use App\Enums\InteraktMessageDirection;
use App\Models\InteraktMessage;

class InteraktMessageStore
{
    public function __construct(
        private readonly InteraktWebhookPayloadParser $payloadParser,
        private readonly InteraktCustomerMatcher $customerMatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertFromWebhook(array $payload): ?InteraktMessage
    {
        $messageId = $this->payloadParser->messageId($payload);

        if ($messageId === null) {
            return null;
        }

        $channelPhoneNumber = $this->payloadParser->channelPhoneNumber($payload);
        $countryCode = $this->payloadParser->countryCode($payload);
        $phoneNumber = $this->payloadParser->phoneNumber($payload);
        $storedPhone = $this->customerMatcher->resolveStoredPhone($countryCode, $phoneNumber, $channelPhoneNumber);

        $existing = InteraktMessage::query()->where('message_id', $messageId)->first();
        $direction = $this->resolveDirection($payload, $existing);
        $deliveryStatus = $this->resolveDeliveryStatus($payload, $existing);
        $templateMetadata = $this->payloadParser->templateMetadata($payload);

        return InteraktMessage::query()->updateOrCreate(
            ['message_id' => $messageId],
            [
                'customer_phone' => $storedPhone ?? $existing?->customer_phone ?? $phoneNumber ?? '',
                'direction' => $direction,
                'message_type' => $this->payloadParser->messageType($payload) ?? $existing?->message_type,
                'text' => $this->payloadParser->messageText($payload)
                    ?? $templateMetadata['body']
                    ?? $existing?->text,
                'media_url' => $this->payloadParser->mediaUrl($payload) ?? $existing?->media_url,
                'template_name' => $this->payloadParser->templateName($payload) ?? $existing?->template_name,
                'template_language' => $this->payloadParser->templateLanguage($payload) ?? $existing?->template_language,
                'delivery_status' => $deliveryStatus,
                'channel_failure_reason' => $this->payloadParser->channelFailureReason($payload) ?? $existing?->channel_failure_reason,
                'channel_error_code' => $this->payloadParser->channelErrorCode($payload) ?? $existing?->channel_error_code,
                'callback_data' => $this->payloadParser->callbackData($payload) ?? $existing?->callback_data,
                'interakt_customer_id' => $this->payloadParser->customerId($payload) ?? $existing?->interakt_customer_id,
                'conversation_id' => $this->payloadParser->conversationId($payload) ?? $existing?->conversation_id,
                'sent_at' => $this->resolveSentAt($payload, $existing),
                'delivered_at' => $this->resolveDeliveredAt($payload, $existing),
                'read_at' => $this->resolveReadAt($payload, $existing),
                'payload' => $payload,
            ],
        );
    }

    private function resolveDirection(array $payload, ?InteraktMessage $existing): InteraktMessageDirection
    {
        if ($this->payloadParser->isIncomingMessage($payload)) {
            return InteraktMessageDirection::Incoming;
        }

        return $existing?->direction ?? InteraktMessageDirection::Outgoing;
    }

    private function resolveDeliveryStatus(array $payload, ?InteraktMessage $existing): InteraktDeliveryStatus
    {
        $status = $this->payloadParser->deliveryStatus($payload);

        if ($status !== null) {
            return $status;
        }

        if ($this->payloadParser->isIncomingMessage($payload)) {
            return InteraktDeliveryStatus::Delivered;
        }

        return $existing?->delivery_status ?? InteraktDeliveryStatus::Sent;
    }

    private function resolveSentAt(array $payload, ?InteraktMessage $existing): ?\Illuminate\Support\Carbon
    {
        return $this->payloadParser->receivedAtUtc($payload)
            ?? $existing?->sent_at
            ?? ($this->payloadParser->isIncomingMessage($payload) ? $this->payloadParser->statusTimestamp($payload) : null);
    }

    private function resolveDeliveredAt(array $payload, ?InteraktMessage $existing): ?\Illuminate\Support\Carbon
    {
        return $this->payloadParser->deliveredAtUtc($payload)
            ?? $existing?->delivered_at
            ?? ($this->payloadParser->deliveryStatus($payload) === InteraktDeliveryStatus::Delivered
                ? $this->payloadParser->statusTimestamp($payload)
                : null);
    }

    private function resolveReadAt(array $payload, ?InteraktMessage $existing): ?\Illuminate\Support\Carbon
    {
        return $this->payloadParser->seenAtUtc($payload)
            ?? $existing?->read_at
            ?? ($this->payloadParser->deliveryStatus($payload) === InteraktDeliveryStatus::Read
                ? $this->payloadParser->statusTimestamp($payload)
                : null);
    }
}
