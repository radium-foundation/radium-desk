<?php

namespace App\Http\Controllers\Pos;

use App\Enums\PosPaymentIntentStatus;
use App\Http\Controllers\Controller;
use App\Models\PosPaymentIntent;
use App\Services\Pos\PosUpiIntentService;
use App\Services\Pos\PosUpiVerificationService;
use App\Support\Inventory\InventoryBranchScope;
use App\Support\Inventory\PosAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UpiPaymentVerificationController extends Controller
{
    public function __construct(
        private readonly PosUpiIntentService $intents,
        private readonly PosUpiVerificationService $verifications,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY),
                403,
            );

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $search = $request->string('q')->trim()->toString();

        $intents = InventoryBranchScope::constrain(
            PosPaymentIntent::query()->with(['branch', 'receivingBankAccount', 'createdBy']),
            $user,
        )
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('public_ref', 'like', '%'.$search.'%')
                        ->orWhere('tr', 'like', '%'.$search.'%')
                        ->orWhere('utr', 'like', '%'.$search.'%')
                        ->orWhere('customer_phone', 'like', '%'.$search.'%')
                        ->orWhere('customer_name', 'like', '%'.$search.'%');
                });
            })
            ->when($request->filled('receiving_bank_account_id'), function ($query) use ($request) {
                $query->where('receiving_bank_account_id', $request->integer('receiving_bank_account_id'));
            })
            ->when($request->filled('amount'), function ($query) use ($request) {
                $query->where('amount', $request->input('amount'));
            })
            ->when($request->filled('from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when(! $request->filled('status'), fn ($query) => $query->where('status', PosPaymentIntentStatus::Pending))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $intents->getCollection()->transform(
            fn (PosPaymentIntent $intent) => $this->intents->refreshExpiry($intent, $user),
        );

        return view('pos.upi.verify.index', [
            'intents' => $intents,
            'accounts' => $this->intents->enabledReceivingAccounts(),
            'filters' => $request->only(['q', 'receiving_bank_account_id', 'amount', 'from', 'to', 'status']),
            'needsBranchAssignment' => InventoryBranchScope::needsAssignment($user),
        ]);
    }

    public function show(Request $request, PosPaymentIntent $intent): View
    {
        $intent->load(['branch', 'receivingBankAccount', 'createdBy', 'sale']);
        InventoryBranchScope::assertCanOperate($request->user(), $intent->branch);
        $intent = $this->intents->refreshExpiry($intent, $request->user());

        return view('pos.upi.verify.show', [
            'intent' => $intent->fresh(['branch', 'receivingBankAccount', 'createdBy', 'sale']) ?? $intent,
        ]);
    }

    public function confirm(Request $request, PosPaymentIntent $intent): RedirectResponse
    {
        $intent->load('branch');
        InventoryBranchScope::assertCanOperate($request->user(), $intent->branch);

        $data = $request->validate([
            'utr' => ['required', 'string', 'max:64'],
            'confirmed_amount' => ['required', 'numeric', 'min:0.01'],
            'bank_checked' => ['accepted'],
        ]);

        $sale = $this->verifications->confirm(
            $intent,
            $request->user(),
            $data['utr'],
            $request->boolean('bank_checked'),
            $data['confirmed_amount'],
        );

        return redirect()->route('pos.sales.show', $sale)
            ->with('status', 'UPI payment verified. Sale '.$sale->sale_no.' completed.');
    }
}
