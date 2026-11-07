<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlanReturnExchangeReplacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('return_case');

        return $case !== null
            && ($this->user()?->can('planReplacement', $case) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'planned_replacement_serial_number' => ['required', 'string', 'max:128'],
            'price_difference_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
