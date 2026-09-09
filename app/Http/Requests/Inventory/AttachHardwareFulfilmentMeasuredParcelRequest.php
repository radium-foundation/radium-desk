<?php

namespace App\Http\Requests\Inventory;

use App\Services\HardwareFulfilment\HardwareShipmentVolumetricWeight;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;

class AttachHardwareFulfilmentMeasuredParcelRequest extends FormRequest
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
        $dim = [
            'required',
            'numeric',
            'gt:0.5',
            'max:'.HardwareShipmentVolumetricWeight::MAX_DIMENSION_CM,
        ];

        return [
            'length' => $dim,
            'breadth' => $dim,
            'height' => $dim,
            'weight' => [
                'required',
                'numeric',
                'gt:0',
                'max:'.HardwareShipmentVolumetricWeight::MAX_WEIGHT_KG,
            ],
            'save_for_future' => ['sometimes', 'boolean'],
            'country' => ['prohibited'],
            'parcel' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'length.gt' => 'Length must be greater than 0.50 cm for the complete packed shipment.',
            'breadth.gt' => 'Breadth must be greater than 0.50 cm for the complete packed shipment.',
            'height.gt' => 'Height must be greater than 0.50 cm for the complete packed shipment.',
            'weight.gt' => 'Actual packed weight must be greater than zero.',
            'country.prohibited' => 'Country cannot be supplied on the measured parcel.',
        ];
    }
}
