<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;

class FetchHardwareFulfilmentCourierOptionsRequest extends FormRequest
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
            'branch' => ['prohibited'],
            'fulfilment_branch_id' => ['prohibited'],
            'pickup' => ['prohibited'],
            'pickup_location' => ['prohibited'],
            'pickup_postcode' => ['prohibited'],
            'delivery_postcode' => ['prohibited'],
            'parcel' => ['prohibited'],
            'weight' => ['prohibited'],
            'length' => ['prohibited'],
            'breadth' => ['prohibited'],
            'height' => ['prohibited'],
            'country' => ['prohibited'],
            'provider_order_id' => ['prohibited'],
            'external_order_id' => ['prohibited'],
            'channel_id' => ['prohibited'],
            'courier_id' => ['prohibited'],
            'cod' => ['prohibited'],
        ];
    }
}
