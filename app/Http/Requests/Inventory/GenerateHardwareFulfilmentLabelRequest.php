<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;

class GenerateHardwareFulfilmentLabelRequest extends FormRequest
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
            'shipment_id' => ['prohibited'],
            'external_shipment_id' => ['prohibited'],
            'channel_id' => ['prohibited'],
            'label_url' => ['prohibited'],
        ];
    }
}
