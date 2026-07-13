<?php

namespace App\Http\Requests;

use App\Models\DeviceModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', DeviceModel::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('device_models', 'name')],
            'code' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'driver_download_url' => ['nullable', 'url', 'max:500'],
            'buy_device_url' => ['nullable', 'url', 'max:500'],
            'buy_rd_service_url' => ['nullable', 'url', 'max:500'],
            'display_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
