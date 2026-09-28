<?php

namespace App\Http\Requests\Inventory;

use App\Enums\HardwareFulfilmentShippingMethod;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectHardwareFulfilmentShippingMethodRequest extends FormRequest
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
            'shipping_method' => ['required', Rule::enum(HardwareFulfilmentShippingMethod::class)],
            'awb' => ['prohibited'],
            'courier_code' => ['prohibited'],
            'courier_name' => ['prohibited'],
            'tracking_url' => ['prohibited'],
            'external_order_id' => ['prohibited'],
            'external_shipment_id' => ['prohibited'],
        ];
    }
}
