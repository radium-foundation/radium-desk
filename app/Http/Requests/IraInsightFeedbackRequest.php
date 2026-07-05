<?php

namespace App\Http\Requests;

use App\Enums\IraInsightFeedbackResponse;
use App\Enums\IraInsightType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IraInsightFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operations-dashboard.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'insight_key' => ['required', 'string', 'max:128'],
            'insight_type' => ['required', 'string', Rule::enum(IraInsightType::class)],
            'response' => ['required', 'string', Rule::enum(IraInsightFeedbackResponse::class)],
            'insight_payload' => ['nullable', 'array'],
        ];
    }
}
