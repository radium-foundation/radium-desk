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
            'display_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
