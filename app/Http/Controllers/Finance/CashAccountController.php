<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceCashAccountRequest;
use App\Http\Requests\Finance\UpdateFinanceCashAccountRequest;
use App\Models\FinanceCashAccount;
use App\Services\Finance\FinanceMasterDataService;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;

class CashAccountController extends Controller
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

    public function store(StoreFinanceCashAccountRequest $request): RedirectResponse
    {
        $this->masterDataService->createCashAccount(
            $request->validated('name'),
            $request->validated('gl_account_id'),
        );

        return redirect()
            ->route('finance.settings.cash-accounts')
            ->with('status', 'finance-cash-account-created');
    }

    public function update(
        UpdateFinanceCashAccountRequest $request,
        FinanceCashAccount $cashAccount,
    ): RedirectResponse {
        $this->masterDataService->updateCashAccount(
            $cashAccount,
            $request->validated('name'),
            $request->validated('gl_account_id'),
        );

        return redirect()
            ->route('finance.settings.cash-accounts')
            ->with('status', 'finance-cash-account-updated');
    }

    public function toggle(FinanceCashAccount $cashAccount): RedirectResponse
    {
        $this->masterDataService->toggleCashAccount($cashAccount, ! $cashAccount->is_active);

        return redirect()
            ->route('finance.settings.cash-accounts')
            ->with(
                'status',
                $cashAccount->fresh()->is_active
                    ? 'finance-cash-account-activated'
                    : 'finance-cash-account-deactivated',
            );
    }
}
