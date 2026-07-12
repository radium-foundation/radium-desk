<?php

namespace App\Http\Requests;

use App\Services\CommunicationActions\CommunicationActionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkspaceCommunicationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $actionKey = (string) $this->route('key');

        if (! app(CommunicationActionRegistry::class)->has($actionKey)) {
            return [
                'key' => ['required'],
            ];
        }

        $definition = app(CommunicationActionRegistry::class)->get($actionKey);
        $rules = [
            'workspace_context' => ['nullable', 'string'],
        ];

        foreach ($definition->variables as $variable) {
            $rules[$variable->key] = [
                $variable->required ? 'required' : 'nullable',
                'string',
                'max:255',
            ];
        }

        $rules['channels'] = ['nullable', 'array'];
        $rules['channels.*'] = [
            'string',
            Rule::in(array_map(
                fn ($channel): string => $channel->value,
                $definition->channels,
            )),
        ];

        return $rules;
    }
}
