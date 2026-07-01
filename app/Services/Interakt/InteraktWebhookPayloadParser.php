<?php

namespace App\Services\Interakt;

use App\Enums\InteraktDeliveryStatus;
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
    public function channelPhoneNumber(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.customer.channel_phone_number'))
            ?? $this->scalarValue(data_get($payload, 'customer.channel_phone_number'));
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
        $message = $this->scalarValue(data_get($payload, 'data.message.message'))
            ?? $this->scalarValue(data_get($payload, 'data.message.text'))
            ?? $this->scalarValue(data_get($payload, 'message.message'));

        if ($message === null) {
            return null;
        }

        if (str_starts_with($message, '[') || str_starts_with($message, '{')) {
            return null;
        }

        return $message;
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
        $metadata = $this->templateMetadata($payload);

        if (filled($metadata['name'])) {
            return $metadata['name'];
        }

        return $this->scalarValue(data_get($payload, 'data.message.template_name'))
            ?? $this->scalarValue(data_get($payload, 'data.message.template.name'))
            ?? $this->scalarValue(data_get($payload, 'data.template.name'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function templateLanguage(array $payload): ?string
    {
        return $this->templateMetadata($payload)['language'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{name: ?string, language: ?string, body: ?string}
     */
    public function templateMetadata(array $payload): array
    {
        $rawTemplate = $this->scalarValue(data_get($payload, 'data.message.raw_template'))
            ?? $this->scalarValue(data_get($payload, 'message.raw_template'));

        if ($rawTemplate === null) {
            return ['name' => null, 'language' => null, 'body' => null];
        }

        $decoded = json_decode($rawTemplate, true);

        if (! is_array($decoded)) {
            return ['name' => null, 'language' => null, 'body' => null];
        }

        $name = $this->scalarValue($decoded['display_name'] ?? null)
            ?? $this->scalarValue($decoded['name'] ?? null);

        return [
            'name' => $name,
            'language' => $this->scalarValue($decoded['language'] ?? null),
            'body' => $this->scalarValue($decoded['body'] ?? null),
        ];
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
    public function customerId(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.customer.id'))
            ?? $this->scalarValue(data_get($payload, 'customer.id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function conversationId(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.conversation.id'))
            ?? $this->scalarValue(data_get($payload, 'data.message.conversation_id'))
            ?? $this->scalarValue(data_get($payload, 'conversation.id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function unreadCount(array $payload): ?int
    {
        foreach ([
            'data.conversation.unread_count',
            'data.message.unread_count',
            'conversation.unread_count',
        ] as $path) {
            $value = data_get($payload, $path);

            if (is_int($value)) {
                return max(0, $value);
            }

            if (is_string($value) && is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function callbackData(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.message.meta_data.source_data.callback_data'))
            ?? $this->scalarValue(data_get($payload, 'data.message.meta_data.callback_data'))
            ?? $this->scalarValue(data_get($payload, 'message.meta_data.source_data.callback_data'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function channelFailureReason(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.message.channel_failure_reason'))
            ?? $this->scalarValue(data_get($payload, 'message.channel_failure_reason'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function channelErrorCode(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'data.message.channel_error_code'))
            ?? $this->scalarValue(data_get($payload, 'message.channel_error_code'));
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
    public function receivedAtUtc(array $payload): ?Carbon
    {
        return $this->parseTimestamp(
            data_get($payload, 'data.message.received_at_utc')
            ?? data_get($payload, 'message.received_at_utc')
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deliveredAtUtc(array $payload): ?Carbon
    {
        return $this->parseTimestamp(
            data_get($payload, 'data.message.delivered_at_utc')
            ?? data_get($payload, 'message.delivered_at_utc')
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function seenAtUtc(array $payload): ?Carbon
    {
        return $this->parseTimestamp(
            data_get($payload, 'data.message.seen_at_utc')
            ?? data_get($payload, 'message.seen_at_utc')
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function statusTimestamp(array $payload): ?Carbon
    {
        $deliveryStatus = $this->deliveryStatus($payload);

        if ($deliveryStatus === InteraktDeliveryStatus::Read) {
            return $this->seenAtUtc($payload)
                ?? $this->deliveredAtUtc($payload)
                ?? $this->receivedAtUtc($payload)
                ?? $this->fallbackTimestamp($payload);
        }

        if ($deliveryStatus === InteraktDeliveryStatus::Delivered) {
            return $this->deliveredAtUtc($payload)
                ?? $this->receivedAtUtc($payload)
                ?? $this->fallbackTimestamp($payload);
        }

        if ($deliveryStatus === InteraktDeliveryStatus::Sent || $deliveryStatus === InteraktDeliveryStatus::Failed) {
            return $this->receivedAtUtc($payload) ?? $this->fallbackTimestamp($payload);
        }

        if ($this->isIncomingMessage($payload)) {
            return $this->receivedAtUtc($payload) ?? $this->fallbackTimestamp($payload);
        }

        return $this->fallbackTimestamp($payload);
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fallbackTimestamp(array $payload): ?Carbon
    {
        foreach ([
            'timestamp',
            'data.timestamp',
            'data.message.timestamp',
            'data.message.created_at_utc',
            'event_time',
        ] as $path) {
            $parsed = $this->parseTimestamp(data_get($payload, $path));

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value);
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
