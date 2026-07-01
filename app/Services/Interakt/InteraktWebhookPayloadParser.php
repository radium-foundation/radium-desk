<?php

namespace App\Services\Interakt;

use App\Enums\InteraktDeliveryStatus;
use App\Enums\InteraktMessageDirection;
use Illuminate\Support\Carbon;

class InteraktWebhookPayloadParser
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function eventType(array $payload): ?string
    {
        foreach (['type', 'event', 'event_type'] as $key) {
            $value = data_get($payload, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function messageId(array $payload): ?string
    {
        foreach ([
            'data.message.id',
            'data.message.message_id',
            'data.id',
            'message.id',
            'message_id',
            'id',
        ] as $path) {
            $value = data_get($payload, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function countryCode(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.customer.country_code'))
            ?? $this->scalarValue(data_get($payload, 'customer.country_code'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function phoneNumber(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.customer.phone_number'))
            ?? $this->scalarValue(data_get($payload, 'customer.phone_number'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function messageText(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.message.message'))
            ?? $this->scalarValue(data_get($payload, 'data.message.text'))
            ?? $this->scalarValue(data_get($payload, 'message.message'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function mediaUrl(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.message.media_url'))
            ?? $this->scalarValue(data_get($payload, 'data.message.mediaUrl'))
            ?? $this->scalarValue(data_get($payload, 'message.media_url'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function templateName(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.message.template_name'))
            ?? $this->scalarValue(data_get($payload, 'data.message.template.name'))
            ?? $this->scalarValue(data_get($payload, 'data.template.name'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function messageType(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.message.message_content_type'))
            ?? $this->scalarValue(data_get($payload, 'data.message.type'))
            ?? $this->scalarValue(data_get($payload, 'message.type'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isIncomingMessage(array $payload): bool
    {
        $eventType = strtolower((string) ($this->eventType($payload) ?? ''));

        if ($eventType === 'message_received') {
            return true;
        }

        $chatMessageType = strtolower((string) (
            $this->scalarValue(data_get($payload, 'data.message.chat_message_type'))
            ?? $this->scalarValue(data_get($payload, 'message.chat_message_type'))
            ?? ''
        ));

        return str_contains($chatMessageType, 'customer');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deliveryStatus(array $payload): ?InteraktDeliveryStatus
    {
        $eventType = strtolower((string) ($this->eventType($payload) ?? ''));

        return match (true) {
            str_contains($eventType, 'failed') => InteraktDeliveryStatus::Failed,
            str_contains($eventType, 'read') => InteraktDeliveryStatus::Read,
            str_contains($eventType, 'delivered') => InteraktDeliveryStatus::Delivered,
            str_contains($eventType, 'sent') => InteraktDeliveryStatus::Sent,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function statusTimestamp(array $payload): ?Carbon
    {
        foreach ([
            'timestamp',
            'data.timestamp',
            'data.message.timestamp',
            'data.message.created_at_utc',
            'event_time',
        ] as $path) {
            $value = data_get($payload, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return Carbon::parse((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function shouldPersistMessage(array $payload): bool
    {
        if ($this->messageId($payload) === null) {
            return false;
        }

        return $this->isIncomingMessage($payload)
            || $this->deliveryStatus($payload) !== null
            || filled($this->messageText($payload))
            || filled($this->templateName($payload));
    }

    private function scalarValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
