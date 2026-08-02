<?php

namespace App\Http\Controllers\Finance;

use App\Enums\FinanceAccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceAccountRequest;
use App\Http\Requests\Finance\StoreOpeningBalanceRequest;
use App\Http\Requests\Finance\UpdateFinancePreferencesRequest;
use App\Models\FinanceAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceJournal;
use App\Models\FinancePaymentMethod;
use App\Models\FinanceSetting;
use App\Services\Finance\AccountBalanceReadModel;
use App\Services\Finance\FinanceSettingsService;
use App\Services\Finance\OpeningBalanceService;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(
        private readonly FinanceSettingsService $settings,
        private readonly OpeningBalanceService $openingBalances,
        private readonly AccountBalanceReadModel $balances,
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

    public function cashAccounts(): View
    {
        return view('finance.settings.cash-accounts', [
            'cashAccounts' => FinanceCashAccount::query()->with('glAccount')->ordered()->get(),
            'glAccounts' => FinanceAccount::query()->active()->where('type', FinanceAccountType::Asset)->ordered()->get(),
        ]);
    }

    public function bankAccounts(): View
    {
        return view('finance.settings.bank-accounts', [
            'bankAccounts' => FinanceBankAccount::query()->with('glAccount')->ordered()->get(),
            'glAccounts' => FinanceAccount::query()->active()->where('type', FinanceAccountType::Asset)->ordered()->get(),
        ]);
    }

    public function paymentMethods(): View
    {
        return view('finance.settings.payment-methods', [
            'paymentMethods' => FinancePaymentMethod::query()->ordered()->get(),
        ]);
    }

    public function expenseCategories(): View
    {
        return view('finance.settings.expense-categories', [
            'expenseCategories' => FinanceExpenseCategory::query()->with('defaultGlAccount')->ordered()->get(),
            'glAccounts' => FinanceAccount::query()->active()->where('type', FinanceAccountType::Expense)->ordered()->get(),
        ]);
    }

    public function vendorMaster(): View
    {
        return view('finance.settings.vendor-master');
    }

    public function chartOfAccounts(): View
    {
        return view('finance.settings.chart-of-accounts', [
            'accounts' => FinanceAccount::query()->ordered()->get(),
            'types' => FinanceAccountType::cases(),
        ]);
    }

    public function storeAccount(StoreFinanceAccountRequest $request): RedirectResponse
    {
        FinanceAccount::query()->create([
            ...$request->validated(),
            'is_system' => false,
            'is_active' => true,
        ]);

        return redirect()
            ->route('finance.settings.chart-of-accounts')
            ->with('status', 'finance-account-created');
    }

    public function toggleAccount(FinanceAccount $account): RedirectResponse
    {
        if ($account->is_system && $account->is_active) {
            return redirect()
                ->route('finance.settings.chart-of-accounts')
                ->with('status', 'finance-account-system-locked');
        }

        $account->update(['is_active' => ! $account->is_active]);

        return redirect()
            ->route('finance.settings.chart-of-accounts')
            ->with(
                'status',
                $account->fresh()->is_active ? 'finance-account-activated' : 'finance-account-deactivated',
            );
    }

    public function financialPreferences(): View
    {
        return view('finance.settings.financial-preferences', [
            'settings' => [
                'ledger_posting_enabled' => FinanceSetting::getValue(FinanceSettingsService::KEY_LEDGER_POSTING_ENABLED, '1'),
                'ledger_cutover_date' => FinanceSetting::getValue(FinanceSettingsService::KEY_CUTOVER_DATE),
                'default_revenue_account_code' => FinanceSetting::getValue(FinanceSettingsService::KEY_DEFAULT_REVENUE),
                'default_refund_account_code' => FinanceSetting::getValue(FinanceSettingsService::KEY_DEFAULT_REFUND),
                'default_bank_clearing_account_code' => FinanceSetting::getValue(FinanceSettingsService::KEY_DEFAULT_BANK_CLEARING),
                'default_cash_account_code' => FinanceSetting::getValue(FinanceSettingsService::KEY_DEFAULT_CASH),
                'opening_equity_account_code' => FinanceSetting::getValue(FinanceSettingsService::KEY_OPENING_EQUITY),
                'default_misc_expense_account_code' => FinanceSetting::getValue(FinanceSettingsService::KEY_DEFAULT_MISC_EXPENSE),
            ],
            'accounts' => FinanceAccount::query()->active()->ordered()->get(),
        ]);
    }

    public function updateFinancialPreferences(UpdateFinancePreferencesRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->settings->update([
            FinanceSettingsService::KEY_LEDGER_POSTING_ENABLED => ($data['ledger_posting_enabled'] ?? false) ? '1' : '0',
            FinanceSettingsService::KEY_CUTOVER_DATE => $data['ledger_cutover_date'] ?? null,
            FinanceSettingsService::KEY_DEFAULT_REVENUE => $data['default_revenue_account_code'] ?? null,
            FinanceSettingsService::KEY_DEFAULT_REFUND => $data['default_refund_account_code'] ?? null,
            FinanceSettingsService::KEY_DEFAULT_BANK_CLEARING => $data['default_bank_clearing_account_code'] ?? null,
            FinanceSettingsService::KEY_DEFAULT_CASH => $data['default_cash_account_code'] ?? null,
            FinanceSettingsService::KEY_OPENING_EQUITY => $data['opening_equity_account_code'] ?? null,
            FinanceSettingsService::KEY_DEFAULT_MISC_EXPENSE => $data['default_misc_expense_account_code'] ?? null,
        ]);

        return redirect()
            ->route('finance.settings.financial-preferences')
            ->with('status', 'finance-preferences-updated');
    }

    public function openingBalances(): View
    {
        $cashAccounts = FinanceCashAccount::query()->with('glAccount')->ordered()->get();

        return view('finance.settings.opening-balances', [
            'cashAccounts' => $cashAccounts->map(fn (FinanceCashAccount $account) => [
                'account' => $account,
                'balance' => $account->gl_account_id
                    ? $this->balances->balance((int) $account->gl_account_id)
                    : 0.0,
            ]),
        ]);
    }

    public function storeOpeningBalance(StoreOpeningBalanceRequest $request): RedirectResponse
    {
        $cashAccount = FinanceCashAccount::query()->findOrFail($request->validated('cash_account_id'));

        $this->openingBalances->postCashOpening(
            $cashAccount,
            (float) $request->validated('amount'),
            $request->user(),
            $request->validated('entry_date'),
        );

        return redirect()
            ->route('finance.settings.opening-balances')
            ->with('status', 'finance-opening-balance-posted');
    }

    public function journals(Request $request): View
    {
        $journals = FinanceJournal::query()
            ->with(['poster', 'lines.account'])
            ->when($request->filled('source_type'), fn ($q) => $q->where('source_type', $request->string('source_type')))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return view('finance.settings.journals', [
            'journals' => $journals,
            'sourceTypes' => \App\Enums\FinanceJournalSourceType::cases(),
            'selectedSourceType' => $request->string('source_type')->toString() ?: null,
        ]);
    }

    public function showJournal(FinanceJournal $journal): View
    {
        $journal->load(['lines.account', 'poster']);

        return view('finance.settings.journal-show', [
            'journal' => $journal,
        ]);
    }
}
