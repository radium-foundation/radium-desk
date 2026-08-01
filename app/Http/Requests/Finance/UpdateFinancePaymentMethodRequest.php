<?php

namespace App\Http\Requests\Finance;

use App\Models\FinancePaymentMethod;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinancePaymentMethodRequest extends FormRequest
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
        /** @var FinancePaymentMethod $paymentMethod */
        $paymentMethod = $this->route('paymentMethod');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('finance_payment_methods', 'name')->ignore($paymentMethod->id),
            ],
        ];
    }
}
