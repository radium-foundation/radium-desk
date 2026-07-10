<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamWorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('workforce-calendar.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'work_start_time' => ['required', 'date_format:H:i'],
            'work_end_time' => ['required', 'date_format:H:i'],
            'lunch_start_time' => ['nullable', 'date_format:H:i', 'required_with:lunch_end_time'],
            'lunch_end_time' => ['nullable', 'date_format:H:i', 'required_with:lunch_start_time', 'after:lunch_start_time'],
            'short_break_count' => ['required', 'integer', 'min:0', 'max:10'],
            'short_break_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'weekly_off_days' => ['nullable', 'array'],
            'weekly_off_days.*' => ['integer', Rule::in([0, 1, 2, 3, 4, 5, 6])],
        ];
    }
}
