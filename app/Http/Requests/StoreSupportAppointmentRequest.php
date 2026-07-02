<?php

namespace App\Http\Requests;

use App\Enums\SupportAppointmentTimeSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        return [
            'preferred_date' => ['required', 'date', 'after_or_equal:today'],
            'preferred_time_slot' => ['required', Rule::enum(SupportAppointmentTimeSlot::class)],
            'phone_number' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'additional_notes' => ['nullable', 'string', 'max:2000'],
        ];
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
