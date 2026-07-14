<?php

namespace App\Http\Requests;

use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundDeductionProfile;
use App\Enums\RefundDifferenceReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRefundRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('refunds.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', Rule::exists('orders', 'id')->whereNull('deleted_at')],
            'incident_id' => [
                'nullable',
                'integer',
                Rule::exists('incidents', 'id')
                    ->where('order_id', $this->integer('order_id'))
                    ->whereNull('deleted_at'),
            ],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
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
    public function attributes(): array
    {
        return [
            'order_id' => 'order',
            'incident_id' => 'incident',
            'amount' => 'amount',
            'reason' => 'reason',
            'customer_preferred_method' => 'customer preferred refund method',
            'communication_channels' => 'communication channels',
        ];
    }

    protected function prepareForValidation(): void
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
}
