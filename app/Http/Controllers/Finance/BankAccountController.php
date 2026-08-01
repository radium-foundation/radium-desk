<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceBankAccountRequest;
use App\Http\Requests\Finance\UpdateFinanceBankAccountRequest;
use App\Models\FinanceBankAccount;
use App\Services\Finance\FinanceMasterDataService;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;

class BankAccountController extends Controller
{
    public function __construct(
        private readonly FinanceMasterDataService $masterDataService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                FinanceAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_FINANCE_SETTINGS_VIEW,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function store(StoreFinanceBankAccountRequest $request): RedirectResponse
    {
        $this->masterDataService->createBankAccount($request->validated());

        return redirect()
            ->route('finance.settings.bank-accounts')
            ->with('status', 'finance-bank-account-created');
    }

    public function update(
        UpdateFinanceBankAccountRequest $request,
        FinanceBankAccount $bankAccount,
    ): RedirectResponse {
        $this->masterDataService->updateBankAccount($bankAccount, $request->validated());

        return redirect()
            ->route('finance.settings.bank-accounts')
            ->with('status', 'finance-bank-account-updated');
    }

    public function toggle(FinanceBankAccount $bankAccount): RedirectResponse
    {
        $this->masterDataService->toggleBankAccount($bankAccount, ! $bankAccount->is_active);

        return redirect()
            ->route('finance.settings.bank-accounts')
            ->with(
                'status',
                $bankAccount->fresh()->is_active
                    ? 'finance-bank-account-activated'
                    : 'finance-bank-account-deactivated',
            );
    }
}
