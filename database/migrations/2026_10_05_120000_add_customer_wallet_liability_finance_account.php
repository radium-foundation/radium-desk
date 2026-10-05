<?php

use App\Enums\FinanceAccountType;
use App\Models\FinanceAccount;
use App\Models\FinanceSetting;
use App\Services\Finance\FinanceSettingsService;
use Database\Seeders\FinanceChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        FinanceAccount::query()->updateOrCreate(
            ['code' => FinanceChartOfAccountsSeeder::CODE_WALLET_LIABILITY],
            [
                'name' => 'Customer Wallet Liability',
                'type' => FinanceAccountType::Liability,
                'is_system' => true,
                'is_active' => true,
                'description' => 'Customer wallet credits and internal wallet refund obligations',
            ],
        );

        if (FinanceSetting::getValue(FinanceSettingsService::KEY_DEFAULT_WALLET_LIABILITY) === null) {
            FinanceSetting::putValue(
                FinanceSettingsService::KEY_DEFAULT_WALLET_LIABILITY,
                FinanceChartOfAccountsSeeder::CODE_WALLET_LIABILITY,
            );
        }
    }

    public function down(): void
    {
        // Non-destructive: retain account and setting if journals may reference them.
    }
};
