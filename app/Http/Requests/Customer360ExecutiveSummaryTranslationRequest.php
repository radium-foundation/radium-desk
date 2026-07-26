<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class Customer360ExecutiveSummaryTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('incident')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'executive_summary' => ['required', 'array', 'min:1', 'max:4'],
            // Single joined operations narrative can exceed the old per-bullet 500 limit.
            'executive_summary.*' => ['required', 'string', 'max:2000'],
            'opinion' => ['required', 'string', 'max:1000'],
            'recommendation' => ['required', 'string', 'max:1000'],
        ];
    }
}
