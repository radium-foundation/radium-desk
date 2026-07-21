<?php

namespace App\Http\Requests;

use App\Services\IdentityCorrection\IdentityCorrectionEligibilityEvaluator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkspaceCorrectDeviceIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        if ($incident === null) {
            return false;
        }

        return app(IdentityCorrectionEligibilityEvaluator::class)->canCorrectDeviceIdentity($incident, $this->user());
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('serial_number')) {
            $this->merge([
                'serial_number' => strtoupper(trim($this->string('serial_number')->toString())),
            ]);
        }

        if ($this->has('confirm_model_switch')) {
            $this->merge([
                'confirm_model_switch' => filter_var($this->input('confirm_model_switch'), FILTER_VALIDATE_BOOL),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_model_id' => [
                'required',
                'integer',
                Rule::exists('device_models', 'id')->where('is_active', true),
            ],
            'serial_number' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:2000'],
            'confirm_model_switch' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'device_model_id' => 'device model',
            'serial_number' => 'serial number',
        ];
    }
}
