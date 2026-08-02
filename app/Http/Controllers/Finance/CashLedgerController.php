<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceCashAccount;
use App\Services\Finance\LedgerAccountMovementReadModel;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CashLedgerController extends Controller
{
    public function __construct(
        private readonly LedgerAccountMovementReadModel $movements,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                FinanceAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_FINANCE_CASH_VIEW,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $accounts = $this->movements->summarizeCashAccounts();
        $selectedId = $request->integer('cash_account_id') ?: ($accounts->first()['id'] ?? null);
        $selected = FinanceCashAccount::query()->with('glAccount')->find($selectedId);

        $glIds = $selected?->gl_account_id ? [(int) $selected->gl_account_id] : [];
        $movement = $this->movements->forAccounts($glIds);

        return view('finance.cash.index', [
            'accounts' => $accounts,
            'selectedAccount' => $selected,
            'balance' => $movement['balance'],
            'lines' => $movement['lines'],
        ]);
    }
}
