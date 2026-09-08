<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;

class SelectHardwareFulfilmentCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return HardwareFulfilmentAccess::allows($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'courier_id' => ['required', 'string', 'max:64'],
            'branch' => ['prohibited'],
            'fulfilment_branch_id' => ['prohibited'],
            'pickup' => ['prohibited'],
            'pickup_location' => ['prohibited'],
            'pickup_postcode' => ['prohibited'],
            'parcel' => ['prohibited'],
            'weight' => ['prohibited'],
            'length' => ['prohibited'],
            'breadth' => ['prohibited'],
            'height' => ['prohibited'],
            'country' => ['prohibited'],
            'provider_order_id' => ['prohibited'],
            'external_order_id' => ['prohibited'],
            'channel_id' => ['prohibited'],
            'cod' => ['prohibited'],
            'collection_mode' => ['prohibited'],
            'payment_method' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'courier_id.required' => 'Select a courier returned by Shiprocket.',
        ];
    }
}
