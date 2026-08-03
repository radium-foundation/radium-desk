<?php

namespace App\Http\Controllers\CashBook;

use App\Enums\CashBookEntryType;
use App\Enums\CashBookExpenseCategory;
use App\Enums\CashBookIncomeSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\CashBook\StoreCashBookEntryRequest;
use App\Http\Requests\CashBook\UpdateCashBookEntryRequest;
use App\Models\CashBookEntry;
use App\Services\CashBook\CashBookEntryService;
use App\Services\CashBook\CashBookLedgerQuery;
use App\Services\CashBook\CashBookSummaryService;
use App\Support\CashBook\CashBookAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CashBookController extends Controller
{
    public function __construct(
        private readonly CashBookEntryService $entries,
        private readonly CashBookSummaryService $summary,
        private readonly CashBookLedgerQuery $ledgerQuery,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(CashBookAccess::allowsView($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'period' => $request->string('period')->trim()->toString() ?: 'today',
            'type' => $request->string('type')->trim()->toString(),
            'date_from' => $request->string('date_from')->trim()->toString(),
            'date_to' => $request->string('date_to')->trim()->toString(),
        ];

        if (! in_array($filters['period'], ['today', 'yesterday', 'this_week', 'this_month', 'custom', 'all'], true)) {
            $filters['period'] = 'today';
        }

        return view('cash-book.index', [
            'summary' => $this->summary->dashboard(),
            'entries' => $this->ledgerQuery->paginate($filters),
            'filters' => $filters,
            'canCreate' => CashBookAccess::allowsCreate($request->user()),
            'canManage' => CashBookAccess::allowsManage($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless(CashBookAccess::allowsCreate($request->user()), 403);

        return view('cash-book.create', $this->formOptions([
            'type' => old('type', CashBookEntryType::Income->value),
            'entry_date' => old('entry_date', now()->toDateString()),
        ]));
    }

    public function store(StoreCashBookEntryRequest $request): RedirectResponse
    {
        $entry = $this->entries->create($request->user(), $request->validated());

        return redirect()
            ->route('cash-book.index')
            ->with('status', 'cash-book-entry-created')
            ->with('status_entry_no', $entry->entry_no);
    }

    public function edit(Request $request, CashBookEntry $cashBookEntry): View
    {
        $this->authorize('update', $cashBookEntry);

        return view('cash-book.edit', $this->formOptions([
            'entry' => $cashBookEntry,
            'type' => old('type', $cashBookEntry->type->value),
            'entry_date' => old('entry_date', $cashBookEntry->entry_date->toDateString()),
            'amount' => old('amount', $cashBookEntry->amount),
            'category' => old('category', $cashBookEntry->category),
            'person' => old('person', $cashBookEntry->person),
            'remark' => old('remark', $cashBookEntry->remark),
        ]));
    }

    public function update(UpdateCashBookEntryRequest $request, CashBookEntry $cashBookEntry): RedirectResponse
    {
        $this->entries->update($cashBookEntry, $request->user(), $request->validated());

        return redirect()
            ->route('cash-book.index')
            ->with('status', 'cash-book-entry-updated');
    }

    public function destroy(Request $request, CashBookEntry $cashBookEntry): RedirectResponse
    {
        $this->authorize('delete', $cashBookEntry);

        $this->entries->delete($cashBookEntry, $request->user());

        return redirect()
            ->route('cash-book.index')
            ->with('status', 'cash-book-entry-deleted');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function formOptions(array $extra = []): array
    {
        return array_merge([
            'incomeSources' => CashBookIncomeSource::cases(),
            'expenseCategories' => CashBookExpenseCategory::cases(),
            'currentUser' => request()->user(),
        ], $extra);
    }
}
