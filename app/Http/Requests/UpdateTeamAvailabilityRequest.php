<?php

namespace App\Http\Requests;

use App\Enums\TeamAvailabilityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Services\Operations\OperationsRoleService::class)
            ->isTeamMember($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'availability_status' => [
                'required',
                'string',
                Rule::in(TeamAvailabilityStatus::values()),
            ],
            'leave_start_date' => [
                Rule::requiredIf(fn (): bool => $this->input('availability_status') === TeamAvailabilityStatus::OnLeave->value),
                'nullable',
                'date',
            ],
            'leave_end_date' => [
                'nullable',
                'date',
                'after_or_equal:leave_start_date',
            ],
        ];
    }
}
