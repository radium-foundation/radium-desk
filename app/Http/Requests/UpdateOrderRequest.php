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

        return $this->user()?->can('update', $order) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('serial_number')) {
            $this->merge([
                'serial_number' => strtoupper(trim($this->string('serial_number')->toString())),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Order $order */
        $order = $this->route('order');

        $serialRules = ['required', 'string', 'max:100'];

        if ($order->isSerialLocked() && ! $this->user()?->can('correctOrderIdentity', $order)) {
            $serialRules[] = Rule::in([$order->serial_number]);
        } else {
            $serialRules[] = Rule::unique('orders', 'serial_number')->ignore($order->id);
        }

        $customerNameRules = ['nullable', 'string', 'max:255'];
        if ($order->isCustomerNameLocked() && ! $this->user()?->can('unlockProtectedIdentityFields', $order)) {
            $customerNameRules[] = Rule::in([(string) ($order->customer_name ?? '')]);
        }

        $customerEmailRules = ['nullable', 'email', 'max:255'];
        if ($order->isCustomerEmailLocked() && ! $this->user()?->can('unlockProtectedIdentityFields', $order)) {
            $customerEmailRules[] = Rule::in([(string) ($order->customer_email ?? '')]);
        }

        $customerPhoneRules = ['nullable', 'string', 'max:50'];
        if ($order->isCustomerPhoneLocked() && ! $this->user()?->can('unlockProtectedIdentityFields', $order)) {
            $customerPhoneRules[] = Rule::in([(string) ($order->customer_phone ?? '')]);
        }

        return [
            'order_id' => [
                'required',
                'string',
                'max:50',
                Rule::unique('orders', 'order_id')->ignore($order->id),
            ],
            'serial_number' => $serialRules,
            'product_name' => ['required', 'string', 'max:255'],
            'device_model_id' => ['nullable', 'required_without:device_model', 'integer', Rule::exists('device_models', 'id')],
            'device_model' => ['nullable', 'required_without:device_model_id', 'string', 'max:255'],
            'customer_name' => $customerNameRules,
            'customer_email' => $customerEmailRules,
            'customer_phone' => $customerPhoneRules,
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'correction_reason' => [
                Rule::requiredIf(fn (): bool => $order->isTransactionLocked()),
                'nullable',
                'string',
                'min:3',
                'max:1000',
            ],
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
            'correction_reason' => 'reason',
        ];
    }
}
