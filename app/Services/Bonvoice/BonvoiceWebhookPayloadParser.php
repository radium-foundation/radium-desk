<?php

namespace App\Services\Bonvoice;

use Illuminate\Support\Carbon;

class BonvoiceWebhookPayloadParser
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function eventType(array $payload): ?string
    {
        $status = $this->status($payload);
        $callType = $this->callType($payload);

        if ($status !== null && $callType !== null) {
            return $callType.':'.$status;
        }

        return $status ?? $callType;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function callId(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'callID'))
            ?? $this->scalarValue(data_get($payload, 'callId'))
            ?? $this->scalarValue(data_get($payload, 'call_id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function leg(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'Leg'))
            ?? $this->scalarValue(data_get($payload, 'leg'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sourceNumber(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'SourceNumber'))
            ?? $this->scalarValue(data_get($payload, 'source_number'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function destinationNumber(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'DestinationNumber'))
            ?? $this->scalarValue(data_get($payload, 'destination_number'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function displayNumber(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'DisplayNumber'))
            ?? $this->scalarValue(data_get($payload, 'display_number'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function direction(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'Direction'))
            ?? $this->scalarValue(data_get($payload, 'direction'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function status(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'Status'))
            ?? $this->scalarValue(data_get($payload, 'status'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function agentStatus(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'AgentStatus'))
            ?? $this->scalarValue(data_get($payload, 'agent_status'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function callType(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'callType'))
            ?? $this->scalarValue(data_get($payload, 'call_type'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function accountId(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'AccountID'))
            ?? $this->scalarValue(data_get($payload, 'account_id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dataSource(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'DataSource'))
            ?? $this->scalarValue(data_get($payload, 'data_source'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function eventId(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'eventID'))
            ?? $this->scalarValue(data_get($payload, 'eventId'))
            ?? $this->scalarValue(data_get($payload, 'event_id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function callbackParentId(array $payload): ?string
    {
        return $this->scalarValue(data_get($payload, 'callBackParentID'))
            ?? $this->scalarValue(data_get($payload, 'callback_parent_id'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function callbackParams(array $payload): ?array
    {
        $value = data_get($payload, 'callBackParams') ?? data_get($payload, 'callback_params');

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function startedAt(array $payload): ?Carbon
    {
        $value = data_get($payload, 'StartTime') ?? data_get($payload, 'start_time');

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isInbound(array $payload): bool
    {
        $direction = strtolower((string) ($this->direction($payload) ?? ''));

        return in_array($direction, ['inbound', 'in', 'incoming'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function customerPhoneNumber(array $payload): ?string
    {
        if ($this->isInbound($payload)) {
            return $this->sourceNumber($payload);
        }

        return $this->destinationNumber($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hasRequiredIdentifiers(array $payload): bool
    {
        return filled($this->callId($payload)) && filled($this->leg($payload));
    }

    /**
     * @param  mixed  $value
     */
    private function scalarValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
