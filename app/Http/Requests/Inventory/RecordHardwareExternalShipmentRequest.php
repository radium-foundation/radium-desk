<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\ExternalCourierCatalog;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordHardwareExternalShipmentRequest extends FormRequest
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
            'courier_code' => ['required', 'string', Rule::in(array_keys(ExternalCourierCatalog::options()))],
            'courier_name' => [
                Rule::requiredIf(fn (): bool => $this->input('courier_code') === ExternalCourierCatalog::OTHER_CODE),
                'nullable',
                'string',
                'max:80',
            ],
            'awb' => ['required', 'string', 'max:64'],
            'tracking_url' => ['nullable', 'string', 'max:1024'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'label' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'manifest' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'external_order_id' => ['prohibited'],
            'external_shipment_id' => ['prohibited'],
            'courier_id' => ['prohibited'],
            'label_url' => ['prohibited'],
        ];
    }
}
