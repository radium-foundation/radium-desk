<?php

namespace App\Http\Requests;

use App\Models\SettingProduct;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', SettingProduct::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_id_enabled' => ['sometimes', 'boolean'],
            'serial_number_enabled' => ['sometimes', 'boolean'],
            'transaction_id_enabled' => ['sometimes', 'boolean'],
            'email_enabled' => ['sometimes', 'boolean'],
            'mobile_enabled' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'order_id_enabled' => $this->boolean('order_id_enabled'),
            'serial_number_enabled' => $this->boolean('serial_number_enabled'),
            'transaction_id_enabled' => $this->boolean('transaction_id_enabled'),
            'email_enabled' => $this->boolean('email_enabled'),
            'mobile_enabled' => $this->boolean('mobile_enabled'),
        ]);
    }
}
