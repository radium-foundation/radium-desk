<?php

namespace App\Http\Requests;

use App\Enums\PreShipmentCancellationShipmentVerification;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePreShipmentCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order
            && $this->user()?->can('create', PreShipmentCancellationRequest::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'min:10', 'max:2000'],
            'blocker_reason' => ['nullable', 'string', 'max:500'],
            'shipment_verification' => [
                'nullable',
                Rule::enum(PreShipmentCancellationShipmentVerification::class),
            ],
            'requested_refund_amount' => ['nullable', 'numeric', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ];
    }
}
