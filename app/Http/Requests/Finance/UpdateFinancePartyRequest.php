<?php

namespace App\Http\Requests\Finance;

use App\Enums\FinancePartyKind;
use App\Enums\FinancePartyRoleType;
use App\Support\Finance\FinanceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinancePartyRequest extends FormRequest
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
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(FinancePartyKind::class)],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', Rule::enum(FinancePartyRoleType::class)],
        ];
    }
}
