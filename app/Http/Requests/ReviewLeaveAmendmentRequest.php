<?php

namespace App\Http\Requests;

use App\Models\LeaveRequestAmendment;
use Illuminate\Foundation\Http\FormRequest;

class ReviewLeaveAmendmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $amendment = $this->route('amendment');

        return $amendment instanceof LeaveRequestAmendment
            && $this->user()?->can('review', $amendment);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'review_notes' => ['required', 'string', 'max:2000'],
            'return_to' => ['nullable', 'string', 'in:index,show'],
        ];
    }
}
