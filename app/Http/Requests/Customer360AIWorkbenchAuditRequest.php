<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class Customer360AIWorkbenchAuditRequest extends FormRequest
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
            'action' => ['required', Rule::in(['viewed', 'copied', 'inserted'])],
            'artifact_key' => ['required', 'string', 'max:100'],
            'target' => ['nullable', 'string', 'max:50'],
            'content_length' => ['nullable', 'integer', 'min:0'],
            'content_hash' => ['nullable', 'string', 'size:64'],
        ];
    }
}
