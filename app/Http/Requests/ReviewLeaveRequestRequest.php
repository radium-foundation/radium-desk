<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');

        return $leaveRequest !== null
            && $this->user()?->can('review', $leaveRequest);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'review_notes' => ['required', 'string', 'max:2000'],
            'authorized_override' => ['sometimes', 'boolean'],
            'authorized_override_reason' => ['nullable', 'string', 'max:2000', 'required_if:authorized_override,1,true'],
        ];
    }
}
