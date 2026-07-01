<?php

namespace App\Services\Interakt;

use App\Enums\InteraktDeliveryStatus;
use App\Enums\InteraktMessageDirection;
use App\Models\InteraktMessage;
use Illuminate\Support\Carbon;

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

        $countryCode = $this->payloadParser->countryCode($payload);
        $phoneNumber = $this->payloadParser->phoneNumber($payload);
        $storedPhone = $this->customerMatcher->resolveStoredPhone($countryCode, $phoneNumber);

        $existing = InteraktMessage::query()->where('message_id', $messageId)->first();
        $direction = $this->resolveDirection($payload, $existing);
        $deliveryStatus = $this->resolveDeliveryStatus($payload, $existing);
        $statusTimestamp = $this->payloadParser->statusTimestamp($payload) ?? now();

        return InteraktMessage::query()->updateOrCreate(
            ['message_id' => $messageId],
            [
                'customer_phone' => $storedPhone ?? $existing?->customer_phone ?? $phoneNumber ?? '',
                'direction' => $direction,
                'message_type' => $this->payloadParser->messageType($payload) ?? $existing?->message_type,
                'text' => $this->payloadParser->messageText($payload) ?? $existing?->text,
                'media_url' => $this->payloadParser->mediaUrl($payload) ?? $existing?->media_url,
                'template_name' => $this->payloadParser->templateName($payload) ?? $existing?->template_name,
                'delivery_status' => $deliveryStatus,
                'sent_at' => $this->resolveSentAt($payload, $existing, $statusTimestamp),
                'delivered_at' => $this->resolveDeliveredAt($deliveryStatus, $existing, $statusTimestamp),
                'read_at' => $this->resolveReadAt($deliveryStatus, $existing, $statusTimestamp),
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

    private function resolveSentAt(array $payload, ?InteraktMessage $existing, Carbon $statusTimestamp): ?Carbon
    {
        if ($existing?->sent_at !== null) {
            return $existing->sent_at;
        }

        if ($this->payloadParser->isIncomingMessage($payload)) {
            return $statusTimestamp;
        }

        $status = $this->payloadParser->deliveryStatus($payload);

        if ($status === InteraktDeliveryStatus::Sent) {
            return $statusTimestamp;
        }

        return null;
    }

    private function resolveDeliveredAt(
        InteraktDeliveryStatus $deliveryStatus,
        ?InteraktMessage $existing,
        Carbon $statusTimestamp,
    ): ?Carbon {
        if ($existing?->delivered_at !== null) {
            return $existing->delivered_at;
        }

        if (in_array($deliveryStatus, [InteraktDeliveryStatus::Delivered, InteraktDeliveryStatus::Read], true)) {
            return $statusTimestamp;
        }

        return null;
    }

    private function resolveReadAt(
        InteraktDeliveryStatus $deliveryStatus,
        ?InteraktMessage $existing,
        Carbon $statusTimestamp,
    ): ?Carbon {
        if ($existing?->read_at !== null) {
            return $existing->read_at;
        }

        if ($deliveryStatus === InteraktDeliveryStatus::Read) {
            return $statusTimestamp;
        }

        return null;
    }
}
