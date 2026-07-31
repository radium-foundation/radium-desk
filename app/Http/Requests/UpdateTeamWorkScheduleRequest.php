<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTeamWorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('workforce-calendar.manage') ?? false;
    }

    /**
     * Type hygiene only — do not apply company-default weekly offs here.
     * Empty/null → company default is owned by TeamWorkScheduleService /
     * WorkCalendarService::normalizeWeeklyOffDays().
     */
    protected function prepareForValidation(): void
    {
        $weeklyOffDays = $this->input('weekly_off_days');

        if (is_array($weeklyOffDays)) {
            $this->merge([
                'weekly_off_days' => collect($weeklyOffDays)
                    ->map(fn (mixed $day): int => (int) $day)
                    ->values()
                    ->all(),
            ]);
        }

        $preset = (string) $this->input('effective_from_preset', 'today');
        $effectiveFrom = match ($preset) {
            'tomorrow' => now()->addDay()->toDateString(),
            'custom' => $this->input('effective_from'),
            default => now()->toDateString(),
        };

        $this->merge([
            'effective_from_preset' => $preset,
            'effective_from' => $effectiveFrom,
        ]);
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
            'effective_from_preset' => ['required', Rule::in(['today', 'tomorrow', 'custom'])],
            'effective_from' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('effective_from_preset') === 'custom' && ! filled($this->input('effective_from'))) {
                $validator->errors()->add('effective_from', 'Choose a custom effective date.');
            }

            try {
                Carbon::parse((string) $this->input('effective_from'));
            } catch (\Throwable) {
                $validator->errors()->add('effective_from', 'Effective from must be a valid date.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'effective_from.required' => 'Effective from is required.',
        ];
    }
}
