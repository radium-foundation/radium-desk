<?php

namespace App\Http\Requests;

use App\Enums\ConversationDisposition;
use App\Enums\ConversationNextAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConversationWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'call_id' => ['nullable', 'string', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_need' => ['nullable', 'string', 'max:2000'],
            'email' => ['nullable', 'email', 'max:190'],
            'whatsapp_same_number' => ['nullable', 'boolean'],
            'whatsapp_number' => ['nullable', 'string', 'max:32'],
            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:120'],
            'order_id_hint' => ['nullable', 'string', 'max:120'],
            'agent_notes' => ['nullable', 'string', 'max:5000'],
            'disposition' => ['nullable', Rule::enum(ConversationDisposition::class)],
            'next_action' => ['nullable', Rule::enum(ConversationNextAction::class)],
            'current_step' => ['nullable', 'string', 'max:64'],
            'completed_fields' => ['nullable', 'array'],
            'completed_fields.*' => ['string', 'max:64'],
            'skipped_fields' => ['nullable', 'array'],
            'skipped_fields.*' => ['string', 'max:64'],
            'mark_completed' => ['nullable', 'boolean'],
            'skip_field' => ['nullable', 'string', 'max:64'],
            'whatsapp_choice' => ['nullable', Rule::in(['same', 'different', 'skip'])],
            'order_id' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mappedAttributes(): array
    {
        $data = $this->safe()->except(['skip_field']);

        if ($this->filled('skip_field')) {
            $data['skipped_fields'] = array_values(array_unique(array_merge(
                $data['skipped_fields'] ?? [],
                [$this->string('skip_field')->toString()],
            )));
        }

        if ($this->string('whatsapp_choice')->toString() === 'same') {
            $data['whatsapp_same_number'] = true;
        } elseif ($this->string('whatsapp_choice')->toString() === 'different') {
            $data['whatsapp_same_number'] = false;
        } elseif ($this->string('whatsapp_choice')->toString() === 'skip') {
            $data['skipped_fields'] = array_values(array_unique(array_merge(
                $data['skipped_fields'] ?? [],
                ['whatsapp'],
            )));
        }

        if ($this->filled('order_id')) {
            $data['order_id_hint'] = $this->string('order_id')->toString();
        }

        return $data;
    }
}
