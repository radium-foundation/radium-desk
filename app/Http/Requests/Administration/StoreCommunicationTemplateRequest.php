<?php

namespace App\Http\Requests\Administration;

use App\Enums\CommunicationTemplates\CommunicationTemplateCategory;
use App\Enums\CommunicationTemplates\CommunicationTemplateChannel;
use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use App\Models\CommunicationTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommunicationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CommunicationTemplate::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'key' => ['nullable', 'string', 'max:100', 'alpha_dash', Rule::unique('communication_templates', 'key')],
            'category' => ['required', Rule::enum(CommunicationTemplateCategory::class)],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::enum(CommunicationTemplateChannel::class)],
            'subject' => ['nullable', 'string', 'max:998'],
            'greeting_style' => ['required', Rule::enum(CommunicationTemplateGreetingStyle::class)],
            'body_html' => ['required', 'string'],
            'signature_mode' => ['required', Rule::enum(CommunicationTemplateSignatureMode::class)],
            'change_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
