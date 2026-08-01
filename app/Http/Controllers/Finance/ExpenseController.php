<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceExpenseRequest;
use App\Http\Requests\Finance\UpdateFinanceExpenseRequest;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceExpense;
use App\Models\FinanceExpenseCategory;
use App\Models\FinancePaymentMethod;
use App\Services\Finance\FinanceExpenseService;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly FinanceExpenseService $expenseService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                FinanceAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_FINANCE_EXPENSES_VIEW,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $search = $request->string('q')->trim()->toString();

        $expenses = FinanceExpense::query()
            ->with(['category', 'paymentMethod', 'cashAccount', 'bankAccount', 'creator'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('expense_no', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->string('status')->trim());
            })
            ->when($request->filled('expense_category_id'), function ($query) use ($request) {
                $query->where('expense_category_id', $request->integer('expense_category_id'));
            })
            ->when($request->filled('date_from'), function ($query) use ($request) {
                $query->whereDate('expense_date', '>=', $request->string('date_from')->toString());
            })
            ->when($request->filled('date_to'), function ($query) use ($request) {
                $query->whereDate('expense_date', '<=', $request->string('date_to')->toString());
            })
            ->latest('expense_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('finance.expenses.index', [
            'expenses' => $expenses,
            'categories' => FinanceExpenseCategory::query()->ordered()->get(),
            'filters' => $request->only(['q', 'status', 'expense_category_id', 'date_from', 'date_to']),
        ]);
    }

    public function create(): View
    {
        return view('finance.expenses.create', $this->formOptions());
    }

    public function store(StoreFinanceExpenseRequest $request): RedirectResponse
    {
        $expense = $this->expenseService->create(
            actor: $request->user(),
            data: $request->validated(),
        );

        return redirect()
            ->route('finance.expenses.show', $expense)
            ->with('status', 'finance-expense-created');
    }

    public function show(FinanceExpense $expense): View
    {
        $expense->load([
            'category',
            'paymentMethod',
            'cashAccount',
            'bankAccount',
            'creator',
            'poster',
        ]);

        return view('finance.expenses.show', [
            'expense' => $expense,
        ]);
    }

    public function edit(FinanceExpense $expense): View|RedirectResponse
    {
        abort_unless($expense->isDraft(), 403);

        $expense->load(['category', 'paymentMethod', 'cashAccount', 'bankAccount']);

        return view('finance.expenses.edit', [
            'expense' => $expense,
            ...$this->formOptions(),
        ]);
    }

    public function update(UpdateFinanceExpenseRequest $request, FinanceExpense $expense): RedirectResponse
    {
        $expense = $this->expenseService->updateDraft($expense, $request->validated());

        return redirect()
            ->route('finance.expenses.show', $expense)
            ->with('status', 'finance-expense-updated');
    }

    public function post(FinanceExpense $expense, Request $request): RedirectResponse
    {
        abort_unless($expense->isDraft(), 403);

        $expense = $this->expenseService->post($expense, $request->user());

        return redirect()
            ->route('finance.expenses.show', $expense)
            ->with('status', 'finance-expense-posted');
    }

    /**
     * @return array{
     *     categories: \Illuminate\Support\Collection<int, FinanceExpenseCategory>,
     *     paymentMethods: \Illuminate\Support\Collection<int, FinancePaymentMethod>,
     *     cashAccounts: \Illuminate\Support\Collection<int, FinanceCashAccount>,
     *     bankAccounts: \Illuminate\Support\Collection<int, FinanceBankAccount>
     * }
     */
    private function formOptions(): array
    {
        return [
            'categories' => FinanceExpenseCategory::query()->where('is_active', true)->ordered()->get(),
            'paymentMethods' => FinancePaymentMethod::query()->where('is_active', true)->ordered()->get(),
            'cashAccounts' => FinanceCashAccount::query()->where('is_active', true)->ordered()->get(),
            'bankAccounts' => FinanceBankAccount::query()->where('is_active', true)->ordered()->get(),
        ];
    }
}
