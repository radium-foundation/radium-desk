<?php

namespace App\Http\Requests;

use App\Models\DeviceModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceModelRequest extends FormRequest
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
        /** @var DeviceModel $deviceModel */
        $deviceModel = $this->route('deviceModel');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('device_models', 'name')->ignore($deviceModel->id),
            ],
            'code' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'display_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
