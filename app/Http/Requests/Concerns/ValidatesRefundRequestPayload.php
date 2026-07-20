<?php

namespace App\Http\Requests\Concerns;

use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundDeductionProfile;
use App\Enums\RefundDifferenceReason;
use Illuminate\Validation\Rule;

trait ValidatesRefundRequestPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function refundRequestValidationRules(?int $orderId = null): array
    {
        $resolvedOrderId = $orderId ?? request()->integer('order_id');

        return [
            'order_id' => ['required', 'integer', Rule::exists('orders', 'id')->whereNull('deleted_at')],
            'incident_id' => [
                'nullable',
                'integer',
                Rule::exists('incidents', 'id')
                    ->where('order_id', $resolvedOrderId)
                    ->whereNull('deleted_at'),
            ],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
            'remarks' => ['required', 'string', 'min:3', 'max:5000'],
            'customer_preferred_method' => [
                'required',
                Rule::enum(CustomerPreferredRefundMethod::class),
            ],
            'deduction_profile_key' => ['nullable', Rule::enum(RefundDeductionProfile::class)],
            'cancellation_charges' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'gst_on_cancellation' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'other_deduction' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'partial_difference_reason' => ['nullable', Rule::enum(RefundDifferenceReason::class)],
            'partial_difference_notes' => ['nullable', 'string', 'max:2000'],
            'communication_channels' => ['nullable', 'array'],
            'communication_channels.*' => ['string', Rule::in(['email', 'whatsapp'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function refundRequestValidationAttributes(): array
    {
        return [
            'order_id' => 'order',
            'incident_id' => 'incident',
            'amount' => 'amount',
            'reason' => 'reason',
            'remarks' => 'remarks',
            'customer_preferred_method' => 'customer preferred refund method',
            'communication_channels' => 'communication channels',
        ];
    }

    protected function mergeRefundRequestDefaults(): void
    {
        if (! $this->filled('customer_preferred_method')) {
            $this->merge([
                'customer_preferred_method' => CustomerPreferredRefundMethod::Opm->value,
            ]);
        }

        if (! $this->has('communication_channels')) {
            $channels = [];

            if ($this->boolean('notify_email', true)) {
                $channels[] = 'email';
            }

            if ($this->boolean('notify_whatsapp', true)) {
                $channels[] = 'whatsapp';
            }

            $this->merge(['communication_channels' => $channels]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function mergeRefundRequestPayloadDefaults(array $payload): array
    {
        if (! array_key_exists('customer_preferred_method', $payload)
            || $payload['customer_preferred_method'] === null
            || $payload['customer_preferred_method'] === '') {
            $payload['customer_preferred_method'] = CustomerPreferredRefundMethod::Opm->value;
        }

        if (! array_key_exists('communication_channels', $payload)) {
            $channels = [];

            if (filter_var($payload['notify_email'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                $channels[] = 'email';
            }

            if (filter_var($payload['notify_whatsapp'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                $channels[] = 'whatsapp';
            }

            $payload['communication_channels'] = $channels;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function mergeRefundRequestIncidentContext(array $payload, int $orderId, int $incidentId): array
    {
        $payload['order_id'] = $orderId;
        $payload['incident_id'] = $incidentId;

        return $payload;
    }
}
