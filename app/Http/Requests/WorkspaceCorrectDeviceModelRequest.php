<?php

namespace App\Http\Requests;

use App\Services\DeviceModelCorrection\DeviceModelCorrectionEligibilityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkspaceCorrectDeviceModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        if ($incident === null) {
            return false;
        }

        return app(DeviceModelCorrectionEligibilityService::class)->canShowAction($incident, $this->user());
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
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'device_model_id' => 'device model',
            'reason' => 'reason',
        ];
    }
}
