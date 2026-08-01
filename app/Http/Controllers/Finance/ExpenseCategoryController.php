<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceExpenseCategoryRequest;
use App\Http\Requests\Finance\UpdateFinanceExpenseCategoryRequest;
use App\Models\FinanceExpenseCategory;
use App\Services\Finance\FinanceMasterDataService;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;

class ExpenseCategoryController extends Controller
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

    public function store(StoreFinanceExpenseCategoryRequest $request): RedirectResponse
    {
        $this->masterDataService->createExpenseCategory($request->validated('name'));

        return redirect()
            ->route('finance.settings.expense-categories')
            ->with('status', 'finance-expense-category-created');
    }

    public function update(
        UpdateFinanceExpenseCategoryRequest $request,
        FinanceExpenseCategory $expenseCategory,
    ): RedirectResponse {
        $this->masterDataService->updateExpenseCategory($expenseCategory, $request->validated('name'));

        return redirect()
            ->route('finance.settings.expense-categories')
            ->with('status', 'finance-expense-category-updated');
    }

    public function toggle(FinanceExpenseCategory $expenseCategory): RedirectResponse
    {
        $this->masterDataService->toggleExpenseCategory($expenseCategory, ! $expenseCategory->is_active);

        return redirect()
            ->route('finance.settings.expense-categories')
            ->with(
                'status',
                $expenseCategory->fresh()->is_active
                    ? 'finance-expense-category-activated'
                    : 'finance-expense-category-deactivated',
            );
    }
}
