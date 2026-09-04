<?php

namespace App\Http\Controllers\Pos;

use App\Enums\PosPaymentIntentStatus;
use App\Http\Controllers\Controller;
use App\Models\PosPaymentIntent;
use App\Services\Pos\PosUpiIntentService;
use App\Support\Inventory\InventoryBranchScope;
use App\Support\Inventory\PosAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UpiIntentController extends Controller
{
    public function __construct(
        private readonly PosUpiIntentService $intents,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(PosAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        abort_unless(
            PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_SELL)
                || PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY),
            403,
        );

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
                        ->orWhere('customer_phone', 'like', '%'.$search.'%')
                        ->orWhere('customer_name', 'like', '%'.$search.'%');
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when(! $request->filled('status'), fn ($query) => $query->where('status', PosPaymentIntentStatus::Pending))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $intents->getCollection()->transform(
            fn (PosPaymentIntent $intent) => $this->intents->refreshExpiry($intent, $user),
        );

        return view('pos.upi.intents.index', [
            'intents' => $intents,
            'filters' => $request->only(['q', 'status']),
            'canVerify' => PosAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY),
            'needsBranchAssignment' => InventoryBranchScope::needsAssignment($user),
        ]);
    }

    public function show(Request $request, PosPaymentIntent $intent): View
    {
        abort_unless(
            PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_SELL)
                || PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY),
            403,
        );

        $intent->load(['branch', 'receivingBankAccount', 'createdBy', 'sale']);
        InventoryBranchScope::assertCanOperate($request->user(), $intent->branch);
        $intent = $this->intents->refreshExpiry($intent, $request->user());

        return view('pos.upi.intents.show', [
            'intent' => $intent->fresh(['branch', 'receivingBankAccount', 'createdBy', 'sale']) ?? $intent,
            'canAbandon' => $this->canCloseUnpaid($request),
            'canVerify' => PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY),
        ]);
    }

    public function abandon(Request $request, PosPaymentIntent $intent): RedirectResponse
    {
        abort_unless($this->canCloseUnpaid($request), 403);
        $intent->load('branch');
        InventoryBranchScope::assertCanOperate($request->user(), $intent->branch);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->intents->abandon($intent, $request->user(), $data['reason'] ?? null);

        return redirect()->route('pos.upi.intents.show', $intent)
            ->with('status', 'UPI payment abandoned. Reserved stock was released. No sale was created.');
    }

    public function cancel(Request $request, PosPaymentIntent $intent): RedirectResponse
    {
        abort_unless($this->canCloseUnpaid($request), 403);
        $intent->load('branch');
        InventoryBranchScope::assertCanOperate($request->user(), $intent->branch);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->intents->cancelUnpaid($intent, $request->user(), $data['reason'] ?? null);

        return redirect()->route('pos.upi.intents.show', $intent)
            ->with('status', 'UPI payment cancelled. Reserved stock was released. No sale was created.');
    }

    private function canCloseUnpaid(Request $request): bool
    {
        $user = $request->user();

        return PosAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_POS_SELL)
            || PosAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY)
            || PosAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_POS_CANCEL);
    }
}
