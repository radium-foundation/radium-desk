<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceExpenseCategory;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinanceExpenseCategoryRequest extends FormRequest
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
        /** @var FinanceExpenseCategory $expenseCategory */
        $expenseCategory = $this->route('expenseCategory');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('finance_expense_categories', 'name')->ignore($expenseCategory->id),
            ],
            'default_gl_account_id' => ['nullable', 'integer', Rule::exists('finance_accounts', 'id')],
        ];
    }
}
