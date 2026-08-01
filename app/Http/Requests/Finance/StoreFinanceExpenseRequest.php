<?php

namespace App\Http\Requests\Finance;

use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFinanceExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return FinanceAccess::allowsPermission(
            $this->user(),
            RolePermissionSeeder::PERMISSION_FINANCE_EXPENSES_VIEW,
        );
    }

    protected function prepareForValidation(): void
    {
        $accountType = $this->string('account_type')->toString();

        if ($accountType === 'cash') {
            $this->merge(['bank_account_id' => null]);
        }

        if ($accountType === 'bank') {
            $this->merge(['cash_account_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expense_date' => ['required', 'date'],
            'expense_category_id' => [
                'required',
                'integer',
                Rule::exists('finance_expense_categories', 'id')->where('is_active', true),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'payment_method_id' => [
                'required',
                'integer',
                Rule::exists('finance_payment_methods', 'id')->where('is_active', true),
            ],
            'account_type' => ['required', 'in:cash,bank'],
            'cash_account_id' => [
                'nullable',
                'required_if:account_type,cash',
                'integer',
                Rule::exists('finance_cash_accounts', 'id')->where('is_active', true),
            ],
            'bank_account_id' => [
                'nullable',
                'required_if:account_type,bank',
                'integer',
                Rule::exists('finance_bank_accounts', 'id')->where('is_active', true),
            ],
            'description' => ['required', 'string', 'max:2000'],
            'receipt' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('cash_account_id') && $this->filled('bank_account_id')) {
                $validator->errors()->add('cash_account_id', 'Select either a cash account or a bank account, not both.');
                $validator->errors()->add('bank_account_id', 'Select either a cash account or a bank account, not both.');
            }

            if (! $this->filled('cash_account_id') && ! $this->filled('bank_account_id')) {
                $validator->errors()->add('account_type', 'Select a cash account or a bank account.');
            }
        });
    }
}
