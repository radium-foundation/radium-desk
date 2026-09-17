<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceJournal;
use App\Models\FinanceLegacyCashEntry;
use App\Support\Finance\FinanceAccess;
use App\Support\Finance\LegacyCashContract;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LegacyCashController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(
                FinanceAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_FINANCE_LEGACY_CASH_VIEW,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'type' => (string) $request->query('type', ''),
            'review_status' => (string) $request->query('review_status', ''),
            'mapped' => (string) $request->query('mapped', ''),
            'amount_type' => (string) $request->query('amount_type', ''),
            'date_from' => (string) $request->query('date_from', ''),
            'date_to' => (string) $request->query('date_to', ''),
        ];

        $query = FinanceLegacyCashEntry::query()->with('deskUser');

        $query->search($filters['q'] !== '' ? $filters['q'] : null);

        if (in_array($filters['type'], [FinanceLegacyCashEntry::TYPE_CREDIT, FinanceLegacyCashEntry::TYPE_DEBIT], true)) {
            $query->where('entry_type', $filters['type']);
        }

        if (in_array($filters['review_status'], [FinanceLegacyCashEntry::REVIEW_OK, FinanceLegacyCashEntry::REVIEW_NEEDS_REVIEW], true)) {
            $query->where('review_status', $filters['review_status']);
        }

        if ($filters['mapped'] === 'yes') {
            $query->whereNotNull('desk_user_id');
        } elseif ($filters['mapped'] === 'no') {
            $query->whereNull('desk_user_id');
        }

        if ($filters['amount_type'] !== '') {
            $query->where('amount_type', $filters['amount_type']);
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('original_created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate('original_created_at', '<=', $filters['date_to']);
        }

        $totals = (clone $query)
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw("SUM(CASE WHEN entry_type = 'credit' THEN 1 ELSE 0 END) as credit_count")
            ->selectRaw("SUM(CASE WHEN entry_type = 'debit' THEN 1 ELSE 0 END) as debit_count")
            ->selectRaw("SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END) as credit_total")
            ->selectRaw("SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE 0 END) as debit_total")
            ->selectRaw('SUM(CASE WHEN desk_user_id IS NULL THEN 1 ELSE 0 END) as unmapped_count')
            ->selectRaw("SUM(CASE WHEN review_status = 'needs_review' THEN 1 ELSE 0 END) as review_count")
            ->first();

        $entries = $query
            ->orderByDesc('original_created_at')
            ->orderByDesc('legacy_transaction_id')
            ->paginate(50)
            ->withQueryString();

        $categories = FinanceLegacyCashEntry::query()
            ->whereNotNull('amount_type')
            ->distinct()
            ->orderBy('amount_type')
            ->pluck('amount_type');

        $opening = FinanceJournal::query()
            ->where('idempotency_key', LegacyCashContract::OPENING_IDEMPOTENCY_KEY)
            ->first();

        return view('finance.legacy-cash.index', [
            'filters' => $filters,
            'entries' => $entries,
            'categories' => $categories,
            'opening' => $opening,
            'totals' => [
                'rows' => (int) ($totals->row_count ?? 0),
                'credits' => (int) ($totals->credit_count ?? 0),
                'debits' => (int) ($totals->debit_count ?? 0),
                'credit_total' => (float) ($totals->credit_total ?? 0),
                'debit_total' => (float) ($totals->debit_total ?? 0),
                'net' => (float) ($totals->credit_total ?? 0) - (float) ($totals->debit_total ?? 0),
                'unmapped' => (int) ($totals->unmapped_count ?? 0),
                'review' => (int) ($totals->review_count ?? 0),
            ],
        ]);
    }
}
