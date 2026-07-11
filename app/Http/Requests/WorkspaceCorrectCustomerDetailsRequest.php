<?php

namespace App\Http\Requests;

use App\Services\CustomerCorrection\CustomerCorrectionEligibilityService;
use Illuminate\Foundation\Http\FormRequest;

class WorkspaceCorrectCustomerDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        if ($incident === null) {
            return false;
        }

        return app(CustomerCorrectionEligibilityService::class)->canShowAction($incident, $this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_name' => 'customer name',
            'customer_email' => 'customer email',
            'customer_phone' => 'customer phone',
            'reason' => 'reason',
        ];
    }
}
