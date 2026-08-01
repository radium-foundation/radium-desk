<?php

namespace Database\Seeders;

use App\Models\FinanceCashAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\FinancePaymentMethod;
use Illuminate\Database\Seeder;

class FinanceMasterDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Cash', 'UPI', 'Bank Transfer', 'Cashfree', 'Other'] as $name) {
            FinancePaymentMethod::query()->updateOrCreate(
                ['name' => $name],
                ['is_active' => true],
            );
        }

        foreach (['Courier', 'Office', 'Marketing', 'Salary', 'Travel', 'Miscellaneous'] as $name) {
            FinanceExpenseCategory::query()->updateOrCreate(
                ['name' => $name],
                ['is_active' => true],
            );
        }

        FinanceCashAccount::query()->updateOrCreate(
            ['name' => 'Main Cash Drawer'],
            ['is_active' => true],
        );
    }
}
