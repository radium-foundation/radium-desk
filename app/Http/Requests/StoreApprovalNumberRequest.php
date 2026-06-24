<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
