<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerOrderHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('orders.view') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'max:160'],
            'order_id' => ['nullable', 'string', 'max:64'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'highlight_order_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $phone = trim((string) $this->input('phone', ''));
            $email = trim((string) $this->input('email', ''));
            $orderId = trim((string) $this->input('order_id', ''));
            $serial = trim((string) $this->input('serial_number', ''));

            if ($phone === '' && $email === '' && $orderId === '' && $serial === '') {
                $validator->errors()->add(
                    'query',
                    'Enter a phone number, email, order ID, or serial number.',
                );
            }
        });
    }
}
