<?php

namespace App\Http\Requests\Finance;

use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpeningBalanceRequest extends FormRequest
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
            'cash_account_id' => ['required', 'integer', Rule::exists('finance_cash_accounts', 'id')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'entry_date' => ['required', 'date'],
        ];
    }
}
