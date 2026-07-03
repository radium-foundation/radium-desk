<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RadiumBoxBatchRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operations-dashboard.view') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }
}
