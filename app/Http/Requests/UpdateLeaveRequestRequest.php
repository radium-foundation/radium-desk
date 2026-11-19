<?php

namespace App\Http\Requests;

use App\Enums\LeaveDuration;
use App\Models\LeaveRequest;
use App\Services\Operations\LeaveRequestService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');

        return $leaveRequest instanceof LeaveRequest
            && $this->user()?->can('update', $leaveRequest);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $earliestStartDate = app(LeaveRequestService::class)
            ->earliestPermittedStartDate()
            ->toDateString();

        return [
            'start_date' => ['required', 'date', 'after_or_equal:'.$earliestStartDate],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'duration' => ['sometimes', 'string', Rule::in([LeaveDuration::FullDay->value])],
            'reason' => ['required', 'string', 'max:2000'],
            'manual_exception' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'duration' => LeaveDuration::FullDay->value,
        ]);
    }
}
