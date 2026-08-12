<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserTelegramSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->route('user');

        return $this->user()?->can('update', $user) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->boolean('reset')) {
            return [
                'reset' => ['required', 'boolean', 'accepted'],
            ];
        }

        return [
            'telegram_chat_id' => ['nullable', 'string', 'max:100'],
            'telegram_notifications_enabled' => ['boolean'],
            'reset' => ['sometimes', 'boolean'],
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

        $this->merge([
            'telegram_notifications_enabled' => $this->boolean('telegram_notifications_enabled'),
            'reset' => $this->boolean('reset'),
        ]);
    }
}
