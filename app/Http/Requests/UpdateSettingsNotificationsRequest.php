<?php

namespace App\Http\Requests;

use App\Models\SettingProduct;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsNotificationsRequest extends FormRequest
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
            'assignment_enabled' => ['sometimes', 'boolean'],
            'transaction_enabled' => ['sometimes', 'boolean'],
            'high_priority_enabled' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'assignment_enabled' => $this->boolean('assignment_enabled'),
            'transaction_enabled' => $this->boolean('transaction_enabled'),
            'high_priority_enabled' => $this->boolean('high_priority_enabled'),
        ]);
    }
}
