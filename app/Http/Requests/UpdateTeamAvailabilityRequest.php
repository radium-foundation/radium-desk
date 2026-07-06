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
        ];
    }
}
