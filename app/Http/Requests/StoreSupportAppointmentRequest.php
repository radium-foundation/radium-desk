<?php

namespace App\Http\Requests;

use App\Services\SupportAppointmentService;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupportAppointmentRequest extends FormRequest
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
        return app(SupportAppointmentService::class)->bookingValidationRules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'preferred_date' => 'preferred date',
            'preferred_time_slot' => 'preferred time slot',
            'phone_number' => 'phone number',
            'additional_notes' => 'additional notes',
        ];
    }
}
