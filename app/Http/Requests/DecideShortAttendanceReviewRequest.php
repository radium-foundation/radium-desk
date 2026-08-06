<?php

namespace App\Http\Requests;

use App\Enums\ShortAttendanceReviewDecision;
use App\Services\Workforce\ShortAttendance\ShortAttendanceReviewService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideShortAttendanceReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ShortAttendanceReviewService::class)->canDecide($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(ShortAttendanceReviewDecision::values())],
            'decision_reason' => ['required', 'string', 'max:1000'],
            'decision_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
