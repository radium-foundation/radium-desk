<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;

class DispatchHardwareExternalShipmentRequest extends FormRequest
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
            'awb' => ['prohibited'],
            'courier_code' => ['prohibited'],
            'courier_name' => ['prohibited'],
            'tracking_url' => ['prohibited'],
            'external_order_id' => ['prohibited'],
            'external_shipment_id' => ['prohibited'],
        ];
    }
}
