<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceCashAccount;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinanceCashAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return FinanceAccess::allowsPermission(
            $this->user(),
            RolePermissionSeeder::PERMISSION_FINANCE_SETTINGS_VIEW,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var FinanceCashAccount $cashAccount */
        $cashAccount = $this->route('cashAccount');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('finance_cash_accounts', 'name')->ignore($cashAccount->id),
            ],
            'gl_account_id' => ['nullable', 'integer', Rule::exists('finance_accounts', 'id')],
        ];
    }
}
