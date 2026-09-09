<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceParty;
use App\Support\Finance\FinanceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinancePartyTermsRequest extends FormRequest
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
        $party = $this->route('party');
        $partyId = $party instanceof FinanceParty ? $party->id : null;

        return [
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'preferred_payment_method' => ['nullable', 'string', 'max:120'],
            'vendor_code' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('finance_party_roles', 'vendor_code')->ignore($partyId, 'party_id'),
            ],
        ];
    }
}
