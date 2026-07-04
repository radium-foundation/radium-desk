<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchCustomerIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('orders.view')
            && $user->can('incidents.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:30'],
            'order_id' => ['nullable', 'string', 'max:50'],
            'serial_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $hasPhone = $this->filled('phone');
            $hasOrderId = $this->filled('order_id');
            $hasSerial = $this->filled('serial_number');

            if (! $hasPhone && ! $hasOrderId && ! $hasSerial) {
                $validator->errors()->add('search', 'Enter a phone number, order ID, or serial number to search.');
            }
        });
    }
}
