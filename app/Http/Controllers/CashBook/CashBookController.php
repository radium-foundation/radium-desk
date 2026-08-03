<?php

namespace App\Http\Controllers\CashBook;

use App\Enums\CashBookEntryType;
use App\Enums\CashBookExpenseCategory;
use App\Enums\CashBookIncomeSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\CashBook\StoreCashBookEntryRequest;
use App\Http\Requests\CashBook\StoreHistoricalCashBookEntryRequest;
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
            'canHistorical' => CashBookAccess::allowsHistoricalImport($request->user()),
            'canBackdate' => CashBookAccess::allowsBackdate($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless(CashBookAccess::allowsCreate($request->user()), 403);

        return view('cash-book.create', $this->formOptions([
            'type' => old('type', CashBookEntryType::Income->value),
            'entry_date' => old('entry_date', now()->toDateString()),
            'canBackdate' => CashBookAccess::allowsBackdate($request->user()),
        ]));
    }

    public function store(StoreCashBookEntryRequest $request): View|RedirectResponse
    {
        $data = $request->safe()->except(['confirmed']);

        if (! $request->boolean('confirmed')) {
            return view('cash-book.confirm', $this->formOptions([
                'payload' => $data,
                'mode' => 'create',
            ]));
        }

        $entry = $this->entries->create($request->user(), $data);

        return redirect()
            ->route('cash-book.index')
            ->with('status', 'cash-book-entry-created')
            ->with('status_entry_no', $entry->entry_no);
    }

    public function editWarning(Request $request, CashBookEntry $cashBookEntry): View
    {
        $this->authorize('update', $cashBookEntry);

        return view('cash-book.edit-warning', [
            'entry' => $cashBookEntry,
        ]);
    }

    public function acknowledgeEdit(Request $request, CashBookEntry $cashBookEntry): RedirectResponse
    {
        $this->authorize('update', $cashBookEntry);

        $this->entries->auditUnlock($cashBookEntry, $request->user());
        $request->session()->put($this->editAckKey($cashBookEntry), true);

        return redirect()->route('cash-book.edit', $cashBookEntry);
    }

    public function edit(Request $request, CashBookEntry $cashBookEntry): View|RedirectResponse
    {
        $this->authorize('update', $cashBookEntry);

        if (! $request->session()->get($this->editAckKey($cashBookEntry))) {
            return redirect()->route('cash-book.edit-warning', $cashBookEntry);
        }

        return view('cash-book.edit', $this->formOptions([
            'entry' => $cashBookEntry,
            'type' => old('type', $cashBookEntry->type->value),
            'entry_date' => old('entry_date', $cashBookEntry->entry_date->toDateString()),
            'amount' => old('amount', $cashBookEntry->amount),
            'category' => old('category', $cashBookEntry->category),
            'person' => old('person', $cashBookEntry->person),
            'remark' => old('remark', $cashBookEntry->remark),
            'backdate_reason' => old('backdate_reason', $cashBookEntry->backdate_reason),
            'canBackdate' => CashBookAccess::allowsBackdate($request->user()),
        ]));
    }

    public function update(UpdateCashBookEntryRequest $request, CashBookEntry $cashBookEntry): RedirectResponse
    {
        if (! $request->session()->get($this->editAckKey($cashBookEntry))) {
            return redirect()->route('cash-book.edit-warning', $cashBookEntry);
        }

        $this->entries->update(
            $cashBookEntry,
            $request->user(),
            $request->safe()->only([
                'type',
                'amount',
                'category',
                'person',
                'remark',
                'entry_date',
                'backdate_reason',
            ]),
        );
        $request->session()->forget($this->editAckKey($cashBookEntry));

        return redirect()
            ->route('cash-book.index')
            ->with('status', 'cash-book-entry-updated');
    }

    public function deleteWarning(Request $request, CashBookEntry $cashBookEntry): View
    {
        $this->authorize('delete', $cashBookEntry);

        return view('cash-book.delete-warning', [
            'entry' => $cashBookEntry,
        ]);
    }

    public function destroy(Request $request, CashBookEntry $cashBookEntry): RedirectResponse
    {
        $this->authorize('delete', $cashBookEntry);

        abort_unless($request->boolean('confirmed'), 403);

        $this->entries->delete($cashBookEntry, $request->user());

        return redirect()
            ->route('cash-book.index')
            ->with('status', 'cash-book-entry-deleted');
    }

    public function historicalCreate(Request $request): View
    {
        abort_unless(CashBookAccess::allowsHistoricalImport($request->user()), 403);

        return view('cash-book.historical-create', $this->formOptions([
            'type' => old('type', CashBookEntryType::Income->value),
            'entry_date' => old('entry_date', now()->subDay()->toDateString()),
        ]));
    }

    public function historicalStore(StoreHistoricalCashBookEntryRequest $request): View|RedirectResponse
    {
        $data = $request->safe()->except(['confirmed']);

        if (! $request->boolean('confirmed')) {
            return view('cash-book.confirm', $this->formOptions([
                'payload' => $data,
                'mode' => 'historical',
            ]));
        }

        $entry = $this->entries->importHistorical($request->user(), $data);

        return redirect()
            ->route('cash-book.index', ['period' => 'all'])
            ->with('status', 'cash-book-historical-imported')
            ->with('status_entry_no', $entry->entry_no);
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
            'canBackdate' => CashBookAccess::allowsBackdate(request()->user()),
        ], $extra);
    }

    private function editAckKey(CashBookEntry $entry): string
    {
        return 'cashbook.edit_ack.'.$entry->id;
    }
}
