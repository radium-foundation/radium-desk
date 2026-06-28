<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignOrderDeviceModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Order $order */
        $order = $this->route('order');

        return $this->user()?->can('assignDeviceModel', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_model_id' => [
                'required',
                'integer',
                Rule::exists('device_models', 'id')->where('is_active', true),
            ],
            'incident_id' => ['nullable', 'integer', 'exists:incidents,id'],
        ];
    }
}
