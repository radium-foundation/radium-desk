<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardBatchDeviceModelComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('incidents.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'incident_ids' => ['required', 'array', 'min:1'],
            'incident_ids.*' => ['required', 'integer', 'exists:incidents,id'],
            'context' => ['nullable', 'string', Rule::in(\App\Enums\WorkspaceContext::values())],
        ];
    }
}
