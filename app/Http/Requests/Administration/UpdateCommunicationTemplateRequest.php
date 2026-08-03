<?php

namespace App\Http\Requests\Administration;

use App\Enums\CommunicationTemplates\CommunicationTemplateCategory;
use App\Enums\CommunicationTemplates\CommunicationTemplateChannel;
use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use App\Models\CommunicationTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCommunicationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CommunicationTemplate $template */
        $template = $this->route('communication_template');

        return $this->user()?->can('update', $template) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(CommunicationTemplateCategory::class)],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::enum(CommunicationTemplateChannel::class)],
            'subject' => ['nullable', 'string', 'max:998'],
            'greeting_style' => ['required', Rule::enum(CommunicationTemplateGreetingStyle::class)],
            'body_html' => ['required', 'string'],
            'signature_mode' => ['required', Rule::enum(CommunicationTemplateSignatureMode::class)],
            'change_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
