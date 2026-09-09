<?php

namespace App\Http\Requests\Finance;

use App\Support\Finance\FinanceAccess;
use Illuminate\Foundation\Http\FormRequest;

class StoreFinancePartyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return FinanceAccess::allowsPartyManage($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_primary' => $this->boolean('is_primary'),
        ]);
    }
}
