<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approvals.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:2000'],
            'incident_id' => [
                'nullable',
                'integer',
                Rule::exists('incidents', 'id')->whereNull('deleted_at'),
            ],
            'return_incident' => [
                'nullable',
                'integer',
                Rule::exists('incidents', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
