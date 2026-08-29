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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'journal',
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
            ...$this->formOptions($expense),
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

    public function receipt(FinanceExpense $expense): StreamedResponse
    {
        abort_unless($expense->hasReceipt(), 404);

        $disk = $expense->receiptDisk();
        abort_unless($disk !== null, 404);

        $filename = basename((string) $expense->receipt_path);

        return Storage::disk($disk)->response(
            $expense->receipt_path,
            $filename,
            ['Content-Disposition' => 'inline; filename="'.$filename.'"'],
        );
    }

    /**
     * @return array{
     *     categories: Collection<int, FinanceExpenseCategory>,
     *     paymentMethods: Collection<int, FinancePaymentMethod>,
     *     cashAccounts: Collection<int, FinanceCashAccount>,
     *     bankAccounts: Collection<int, FinanceBankAccount>
     * }
     */
    private function formOptions(?FinanceExpense $expense = null): array
    {
        return [
            'categories' => $this->formCategories($expense),
            'paymentMethods' => $this->activeOrCurrent(
                FinancePaymentMethod::query()->where('is_active', true)->ordered()->get(),
                $expense?->paymentMethod,
            ),
            'cashAccounts' => $this->activeOrCurrent(
                FinanceCashAccount::query()->where('is_active', true)->ordered()->get(),
                $expense?->cashAccount,
            ),
            'bankAccounts' => $this->activeOrCurrent(
                FinanceBankAccount::query()->where('is_active', true)->ordered()->get(),
                $expense?->bankAccount,
            ),
        ];
    }

    /**
     * @return Collection<int, FinanceExpenseCategory>
     */
    private function formCategories(?FinanceExpense $expense): Collection
    {
        return $this->activeOrCurrent(
            FinanceExpenseCategory::query()->where('is_active', true)->ordered()->get(),
            $expense?->category,
        );
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $active
     * @param  TModel|null  $current
     * @return Collection<int, TModel>
     */
    private function activeOrCurrent(Collection $active, ?Model $current): Collection
    {
        if ($current === null || $active->contains(fn ($item): bool => (int) $item->getKey() === (int) $current->getKey())) {
            return $active;
        }

        return $active->prepend($current)->unique(fn ($item) => $item->getKey())->values();
    }
}
