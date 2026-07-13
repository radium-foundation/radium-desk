<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('orders.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'string', 'max:50', Rule::unique('orders', 'order_id')],
            'serial_number' => ['required', 'string', 'max:100', Rule::unique('orders', 'serial_number')],
            'product_name' => ['required', 'string', 'max:255'],
            'device_model_id' => ['required', 'integer', Rule::exists('device_models', 'id')],
            'device_model' => ['prohibited'],
            'transaction_id' => ['nullable', 'string', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'order_id' => 'order ID',
            'serial_number' => 'serial number',
            'product_name' => 'product name',
            'device_model' => 'device model',
            'device_model_id' => 'device model',
            'transaction_id' => 'transaction ID',
            'customer_name' => 'customer name',
            'customer_email' => 'customer email',
            'customer_phone' => 'customer phone',
        ];
    }
}
