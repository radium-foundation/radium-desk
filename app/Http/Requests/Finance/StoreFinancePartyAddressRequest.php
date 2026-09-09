<?php

namespace App\Http\Requests\Finance;

use App\Enums\FinancePartyAddressKind;
use App\Support\Finance\FinanceAccess;
use App\Support\Finance\IndianStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancePartyAddressRequest extends FormRequest
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
            'label' => ['nullable', 'string', 'max:64'],
            'kind' => ['required', Rule::enum(FinancePartyAddressKind::class)],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'state' => ['required', 'string', Rule::in(IndianStates::names())],
            'postal_code' => ['required', 'string', 'regex:/^[1-9][0-9]{5}$/'],
            'country' => ['nullable', 'string', 'max:64'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'is_default_billing' => ['sometimes', 'boolean'],
            'is_default_shipping' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_default_billing' => $this->boolean('is_default_billing'),
            'is_default_shipping' => $this->boolean('is_default_shipping'),
        ]);
    }
}
