<?php

namespace App\Http\Requests;

use App\Enums\CommunicationActionKey;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\CommunicationActions\CommunicationActionTargetProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'communication_target' => ['nullable', 'string', 'max:255'],
            'delivery_channel' => ['nullable', 'string', Rule::in(['both', 'whatsapp', 'email'])],
            'communication_action_key' => ['nullable', 'string'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $target = trim((string) $this->input('communication_target', ''));

            if ($target === '') {
                return;
            }

            $actionKey = CommunicationActionKey::tryFrom((string) $this->route('key'));

            if ($actionKey === null) {
                return;
            }

            $registry = app(CommunicationActionTargetProviderRegistry::class);

            if (! $registry->hasProviderFor($actionKey)) {
                return;
            }

            $incident = $this->route('incident');

            if ($incident === null || ! $registry->isValidTarget($actionKey, $target, $incident)) {
                $validator->errors()->add('communication_target', 'The selected target is invalid.');
            }
        });
    }
}
