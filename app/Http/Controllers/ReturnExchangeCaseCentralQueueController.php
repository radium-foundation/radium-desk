<?php

namespace App\Http\Controllers;

use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\InventoryBranch;
use App\Models\ReturnExchangeCase;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReturnExchangeCaseCentralQueueController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(
            $request->user() !== null && ReturnExchangeCaseBranchAccess::hasCentralAccess($request->user()),
            403,
            'Central return-case queue is restricted to authorized central staff.',
        );

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ReturnExchangeCaseStatus::class)],
            'source_kind' => ['nullable', Rule::enum(ReturnExchangeCaseSourceKind::class)],
            'branch_id' => ['nullable', 'integer', 'exists:inventory_branches,id'],
            'submitted_from' => ['nullable', 'date'],
            'submitted_to' => ['nullable', 'date', 'after_or_equal:submitted_from'],
            'receipt_state' => ['nullable', 'in:any,unreceived,partial,awaiting_inspection,fully_inspected'],
        ]);

        $query = ReturnExchangeCase::query()
            ->with([
                'branch',
                'lines',
                'receipts.serial',
                'inventorySale.statutoryInvoice',
                'order' => fn ($query) => $query->withCount('refundRequests'),
                'posRefundRequest.requester',
                'posRefundRequest.reviewer',
            ])
            ->withCount(['lines', 'receipts'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['source_kind'])) {
            $query->where('source_kind', $filters['source_kind']);
        }

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        if (! empty($filters['submitted_from'])) {
            $query->whereDate('created_at', '>=', $filters['submitted_from']);
        }

        if (! empty($filters['submitted_to'])) {
            $query->whereDate('created_at', '<=', $filters['submitted_to']);
        }

        $receiptState = $filters['receipt_state'] ?? 'any';
        if ($receiptState !== 'any') {
            $this->applyReceiptStateFilter($query, $receiptState);
        }

        $cases = $query->paginate(25)->withQueryString();

        return view('return-cases.central-queue', [
            'cases' => $cases,
            'filters' => array_merge([
                'status' => null,
                'source_kind' => null,
                'branch_id' => null,
                'submitted_from' => null,
                'submitted_to' => null,
                'receipt_state' => 'any',
            ], $filters),
            'statuses' => ReturnExchangeCaseStatus::cases(),
            'sourceKinds' => ReturnExchangeCaseSourceKind::cases(),
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('name')->get(),
            'filtersActive' => collect($filters)->filter(fn ($value) => $value !== null && $value !== '' && $value !== 'any')->isNotEmpty(),
        ]);
    }

    /**
     * @param  Builder<ReturnExchangeCase>  $query
     */
    private function applyReceiptStateFilter(Builder $query, string $state): void
    {
        match ($state) {
            'unreceived' => $query->whereDoesntHave('receipts'),
            'partial' => $query
                ->whereHas('receipts')
                ->whereRaw(
                    '(select count(*) from return_exchange_case_receipts where return_exchange_case_receipts.return_exchange_case_id = return_exchange_cases.id) '
                    .'< (select count(*) from return_exchange_case_lines where return_exchange_case_lines.return_exchange_case_id = return_exchange_cases.id)',
                ),
            'awaiting_inspection' => $query->whereHas('receipts', function (Builder $inner): void {
                $inner->whereNull('inspected_at');
            }),
            'fully_inspected' => $query
                ->whereHas('receipts')
                ->whereDoesntHave('receipts', function (Builder $inner): void {
                    $inner->whereNull('inspected_at');
                })
                ->whereRaw(
                    '(select count(*) from return_exchange_case_receipts where return_exchange_case_receipts.return_exchange_case_id = return_exchange_cases.id) '
                    .'>= (select count(*) from return_exchange_case_lines where return_exchange_case_lines.return_exchange_case_id = return_exchange_cases.id)',
                ),
            default => null,
        };
    }
}
