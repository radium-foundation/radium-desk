<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceParty;
use App\Models\FinancePartyGstRegistration;
use App\Support\Finance\FinanceAccess;
use App\Support\Finance\PanNumber;
use App\Support\Finance\PartyGstin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFinancePartyGstRequest extends FormRequest
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
        $gst = $this->route('gst');
        $gstId = $gst instanceof FinancePartyGstRegistration ? $gst->id : null;

        return [
            'gstin' => [
                'required',
                'string',
                'max:15',
                Rule::unique('finance_party_gst_registrations', 'gstin')
                    ->where(fn ($query) => $query->where('party_id', $partyId))
                    ->ignore($gstId),
            ],
            'registered_name' => ['nullable', 'string', 'max:255'],
            'pan' => ['nullable', 'string', 'max:10'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'gstin' => PartyGstin::normalize($this->input('gstin')),
            'pan' => PanNumber::normalize($this->input('pan')),
            'is_primary' => $this->boolean('is_primary'),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $gstin = $this->input('gstin');
            if (is_string($gstin) && $gstin !== '' && ! PartyGstin::isValid($gstin)) {
                $validator->errors()->add(
                    'gstin',
                    'Enter a valid GSTIN (state code, PAN fragment, entity, Z checksum). Length alone is not enough.',
                );
            }

            $pan = $this->input('pan');
            if (is_string($pan) && $pan !== '' && ! PanNumber::isValid($pan)) {
                $validator->errors()->add('pan', 'Enter a valid PAN.');
            }
        });
    }
}
