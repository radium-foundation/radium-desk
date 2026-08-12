<?php

namespace App\Http\Requests;

use App\Enums\LeaveAmendmentType;
use App\Enums\LeaveDuration;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLeaveAmendmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');

        return $leaveRequest instanceof LeaveRequest
            && $this->user()?->can('requestAmendment', $leaveRequest);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'type' => ['required', 'string', Rule::in(LeaveAmendmentType::values())],
            'reason' => ['required', 'string', 'max:2000'],
        ];

        if ($this->input('type') === LeaveAmendmentType::DateChange->value) {
            $rules['proposed_start_date'] = ['required', 'date'];
            $rules['proposed_end_date'] = ['required', 'date', 'after_or_equal:proposed_start_date'];
            $rules['proposed_duration'] = ['required', 'string', Rule::in(LeaveDuration::values())];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('type') !== LeaveAmendmentType::DateChange->value) {
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
