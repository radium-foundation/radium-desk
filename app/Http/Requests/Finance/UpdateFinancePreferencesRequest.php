<?php

namespace App\Http\Requests\Finance;

use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinancePreferencesRequest extends FormRequest
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
        return [
            'ledger_posting_enabled' => ['sometimes', 'boolean'],
            'ledger_cutover_date' => ['nullable', 'date'],
            'default_revenue_account_code' => ['nullable', 'string', Rule::exists('finance_accounts', 'code')],
            'default_refund_account_code' => ['nullable', 'string', Rule::exists('finance_accounts', 'code')],
            'default_bank_clearing_account_code' => ['nullable', 'string', Rule::exists('finance_accounts', 'code')],
            'default_cash_account_code' => ['nullable', 'string', Rule::exists('finance_accounts', 'code')],
            'opening_equity_account_code' => ['nullable', 'string', Rule::exists('finance_accounts', 'code')],
            'default_misc_expense_account_code' => ['nullable', 'string', Rule::exists('finance_accounts', 'code')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ledger_posting_enabled' => $this->boolean('ledger_posting_enabled'),
        ]);
    }
}
