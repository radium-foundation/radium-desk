<?php

namespace App\Services\Interakt;

class InteraktFlowWebhookPayloadParser
{
    public const EVENT_API_FLOW_RESPONSE = 'message_api_flow_response';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function eventType(array $payload): ?string
    {
        $value = data_get($payload, 'type');

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isFlowResponse(array $payload): bool
    {
        return $this->eventType($payload) === self::EVENT_API_FLOW_RESPONSE;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function responseJson(array $payload): ?array
    {
        $raw = data_get($payload, 'data.message.message.nfm_reply.response_json');

        if ($raw === null) {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $responseJson
     */
    public function flowToken(array $responseJson): ?string
    {
        $token = data_get($responseJson, 'flow_token');

        if (! is_scalar($token)) {
            return null;
        }

        $token = trim((string) $token);

        if ($token === '' || $token === 'unused') {
            return null;
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $responseJson
     * @return array<string, mixed>
     */
    public function bookingData(array $responseJson): array
    {
        return [
            'preferred_date' => data_get($responseJson, 'preferred_date'),
            'preferred_time_slot' => data_get($responseJson, 'preferred_time_slot'),
            'phone_number' => data_get($responseJson, 'phone_number'),
            'additional_notes' => data_get($responseJson, 'additional_notes'),
        ];
    }

}
