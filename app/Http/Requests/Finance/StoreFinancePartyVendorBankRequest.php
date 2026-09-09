<?php

namespace App\Http\Requests\Finance;

use App\Support\Finance\FinanceAccess;
use App\Support\Finance\IfscCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFinancePartyVendorBankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return FinanceAccess::allowsPartyManage($this->user())
            && FinanceAccess::allowsPartyBank($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:255'],
            'account_holder_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'min:6', 'max:32'],
            'ifsc' => ['required', 'string', 'max:11'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ifsc' => IfscCode::normalize($this->input('ifsc')),
            'is_primary' => $this->boolean('is_primary'),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ifsc = $this->input('ifsc');
            if (is_string($ifsc) && $ifsc !== '' && ! IfscCode::isValid($ifsc)) {
                $validator->errors()->add('ifsc', 'Enter a valid IFSC.');
            }
        });
    }
}
