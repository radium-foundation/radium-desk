<?php

namespace App\Http\Requests;

use App\Enums\LeaveDuration;
use App\Models\LeaveRequest;
use App\Services\Operations\LeaveRequestService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ManageLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');

        return $leaveRequest instanceof LeaveRequest
            && $this->user()?->can('manage', $leaveRequest);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->routeIs('leave-requests.manage-cancel')) {
            return [
                'reason' => ['required', 'string', 'max:2000'],
                'review_notes' => ['required', 'string', 'max:2000'],
            ];
        }

        $earliestStartDate = app(LeaveRequestService::class)
            ->earliestPermittedStartDate()
            ->toDateString();

        return [
            'proposed_start_date' => ['required', 'date', 'after_or_equal:'.$earliestStartDate],
            'proposed_end_date' => ['required', 'date', 'after_or_equal:proposed_start_date'],
            'proposed_duration' => ['required', 'string', Rule::in(LeaveDuration::values())],
            'reason' => ['required', 'string', 'max:2000'],
            'review_notes' => ['required', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->routeIs('leave-requests.manage-cancel')) {
                return;
            }

            if ($this->input('proposed_duration') !== LeaveDuration::HalfDay->value) {
                return;
            }

            $start = (string) $this->input('proposed_start_date', '');
            $end = (string) $this->input('proposed_end_date', '');

            if ($start !== '' && $end !== '' && $start !== $end) {
                $validator->errors()->add('proposed_duration', 'Half day leave must be for a single date.');
            }
        });
    }
}
