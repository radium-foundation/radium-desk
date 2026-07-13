<?php

namespace App\Http\Requests;

use App\Models\DeviceModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceModelAliasRequest extends FormRequest
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
            'device_model_id' => ['required', 'integer', Rule::exists('device_models', 'id')],
            'alias' => ['required', 'string', 'max:255'],
        ];
    }
}
