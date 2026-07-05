<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileTelegramUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'telegram_chat_id' => ['nullable', 'string', 'max:100'],
            'telegram_notifications_enabled' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $chatId = $this->input('telegram_chat_id');

        if (is_string($chatId)) {
            $this->merge([
                'telegram_chat_id' => trim($chatId) === '' ? null : trim($chatId),
            ]);
        }
    }
}
