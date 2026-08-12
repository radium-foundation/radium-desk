<?php

namespace App\Http\Requests;

use App\Enums\LeaveDuration;
use App\Models\LeaveRequest;
use App\Services\Operations\LeaveRequestService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'duration' => ['required', 'string', Rule::in(LeaveDuration::values())],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('duration') !== LeaveDuration::HalfDay->value) {
                return;
            }

            $start = (string) $this->input('start_date', '');
            $end = (string) $this->input('end_date', '');

            if ($start !== '' && $end !== '' && $start !== $end) {
                $validator->errors()->add('duration', 'Half day leave must be for a single date.');
            }
        });
    }
}
