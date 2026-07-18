<?php

namespace App\Http\Requests;

use App\Services\SettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderServiceCaseRequest extends FormRequest
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
        $settingService = app(SettingService::class);

        return [
            'source' => ['required', 'string', Rule::in($settingService->enabledSourceKeys())],
            'notes' => ['required', 'string', 'max:5000'],
            'high_priority' => ['sometimes', 'boolean'],
            'incoming_email_message_id' => ['sometimes', 'nullable', 'integer', 'exists:incoming_email_messages,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'source' => 'source',
            'notes' => 'problem description',
            'high_priority' => 'high priority',
        ];
    }
}
