<?php

namespace App\Http\Requests;

use App\Enums\RecognitionRecommendation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideWorkRecognitionReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('workforce.recognition.review') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(RecognitionRecommendation::values())],
            'decision_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
