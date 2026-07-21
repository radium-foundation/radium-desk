<?php

namespace App\Http\Requests;

use App\Services\IdentityCorrection\IdentityCorrectionEligibilityEvaluator;
use Illuminate\Foundation\Http\FormRequest;

class WorkspaceCorrectSerialNumberRequest extends FormRequest
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
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'serial_number' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'serial_number' => 'serial number',
            'reason' => 'reason',
        ];
    }
}
