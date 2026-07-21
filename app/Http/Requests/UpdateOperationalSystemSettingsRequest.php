<?php

namespace App\Http\Requests;

use App\Models\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateOperationalSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', SystemSetting::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $submitted = $this->settingsInput();
            $definitions = config('system_settings.settings', []);

            foreach (array_keys($definitions) as $key) {
                if (! array_key_exists($key, $submitted)) {
                    $validator->errors()->add("settings.{$key}", 'All settings must be submitted.');

                    continue;
                }

                $type = $definitions[$key]['type'] ?? 'string';

                if ($type === 'boolean' && ! in_array($submitted[$key], [null, '0', '1', 0, 1, true, false], true)) {
                    $validator->errors()->add("settings.{$key}", 'Invalid boolean value.');
                }
            }

            foreach (array_keys($submitted) as $key) {
                if (! array_key_exists($key, $definitions)) {
                    $validator->errors()->add("settings.{$key}", 'Unknown setting.');
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedSettings(): array
    {
        $validated = [];
        $definitions = config('system_settings.settings', []);
        $submitted = $this->settingsInput();

        foreach ($definitions as $key => $definition) {
            $type = $definition['type'] ?? 'string';

            if (($definition['disabled'] ?? false) === true) {
                $validated[$key] = match ($type) {
                    'boolean' => (bool) ($definition['default'] ?? false),
                    'integer' => (int) ($definition['default'] ?? 0),
                    default => $definition['default'] ?? null,
                };

                continue;
            }

            $raw = $submitted[$key] ?? null;

            $validated[$key] = match ($type) {
                'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'integer' => (int) $raw,
                default => $raw,
            };
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsInput(): array
    {
        $settings = $this->input('settings');

        return is_array($settings) ? $settings : [];
    }
}
