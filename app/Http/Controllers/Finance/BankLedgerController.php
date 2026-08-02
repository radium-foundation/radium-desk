<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceBankAccount;
use App\Services\Finance\LedgerAccountMovementReadModel;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BankLedgerController extends Controller
{
    public function __construct(
        private readonly LedgerAccountMovementReadModel $movements,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                FinanceAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_FINANCE_BANK_VIEW,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $accounts = $this->movements->summarizeBankAccounts();
        $selectedId = $request->integer('bank_account_id') ?: ($accounts->first()['id'] ?? null);
        $selected = FinanceBankAccount::query()->with('glAccount')->find($selectedId);

        $glIds = $selected?->gl_account_id ? [(int) $selected->gl_account_id] : [];
        $movement = $this->movements->forAccounts($glIds);

        return view('finance.bank.index', [
            'accounts' => $accounts,
            'selectedAccount' => $selected,
            'balance' => $movement['balance'],
            'lines' => $movement['lines'],
        ]);
    }
}
