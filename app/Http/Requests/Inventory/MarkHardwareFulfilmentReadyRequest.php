<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;

class MarkHardwareFulfilmentReadyRequest extends FormRequest
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
            'serials' => ['prohibited'],
            'invoice' => ['prohibited'],
            'parcel' => ['prohibited'],
            'shipment_id' => ['prohibited'],
            'courier_id' => ['prohibited'],
            'awb' => ['prohibited'],
            'channel_id' => ['prohibited'],
        ];
    }
}
