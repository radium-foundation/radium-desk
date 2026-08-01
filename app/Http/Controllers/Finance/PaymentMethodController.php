<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinancePaymentMethodRequest;
use App\Http\Requests\Finance\UpdateFinancePaymentMethodRequest;
use App\Models\FinancePaymentMethod;
use App\Services\Finance\FinanceMasterDataService;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;

class PaymentMethodController extends Controller
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

    public function store(StoreFinancePaymentMethodRequest $request): RedirectResponse
    {
        $this->masterDataService->createPaymentMethod($request->validated('name'));

        return redirect()
            ->route('finance.settings.payment-methods')
            ->with('status', 'finance-payment-method-created');
    }

    public function update(
        UpdateFinancePaymentMethodRequest $request,
        FinancePaymentMethod $paymentMethod,
    ): RedirectResponse {
        $this->masterDataService->updatePaymentMethod($paymentMethod, $request->validated('name'));

        return redirect()
            ->route('finance.settings.payment-methods')
            ->with('status', 'finance-payment-method-updated');
    }

    public function toggle(FinancePaymentMethod $paymentMethod): RedirectResponse
    {
        $this->masterDataService->togglePaymentMethod($paymentMethod, ! $paymentMethod->is_active);

        return redirect()
            ->route('finance.settings.payment-methods')
            ->with(
                'status',
                $paymentMethod->fresh()->is_active
                    ? 'finance-payment-method-activated'
                    : 'finance-payment-method-deactivated',
            );
    }
}
