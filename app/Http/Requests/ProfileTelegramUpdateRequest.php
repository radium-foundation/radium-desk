<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileTelegramUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if ($user->can('users.manage')) {
            return true;
        }

        return blank($user->telegram_chat_id);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();

        if ($user !== null && $user->can('users.manage')) {
            return [
                'telegram_chat_id' => ['nullable', 'string', 'max:100'],
                'telegram_notifications_enabled' => ['boolean'],
            ];
        }

        return [
            'telegram_chat_id' => ['required', 'string', 'max:100'],
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

        if ($this->user()?->can('users.manage')) {
            $this->merge([
                'telegram_notifications_enabled' => $this->boolean('telegram_notifications_enabled'),
            ]);
        }
    }
}
