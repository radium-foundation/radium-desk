<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Order $order */
        $order = $this->route('order');

        return $this->user()?->can('orders.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Order $order */
        $order = $this->route('order');

        return [
            'order_id' => [
                'required',
                'string',
                'max:50',
                Rule::unique('orders', 'order_id')->ignore($order->id),
            ],
            'serial_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('orders', 'serial_number')->ignore($order->id),
            ],
            'product_name' => ['required', 'string', 'max:255'],
            'device_model' => ['required', 'string', 'max:255'],
            'transaction_id' => ['nullable', 'string', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::enum(OrderStatus::class)],
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
            'transaction_id' => 'transaction ID',
            'customer_name' => 'customer name',
            'customer_email' => 'customer email',
            'customer_phone' => 'customer phone',
        ];
    }
}
