<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\InventorySale;
use App\Services\Inventory\PosSaleService;
use App\Support\Inventory\InventoryBranchScope;
use App\Support\Inventory\PosAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function __construct(
        private readonly PosSaleService $sales,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(PosAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $search = $request->string('q')->trim()->toString();

        $sales = InventoryBranchScope::constrain(
            InventorySale::query()->with(['branch', 'customer', 'createdBy']),
            $user,
        )
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('sale_no', 'like', '%'.$search.'%')
                        ->orWhere('invoice_number', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', function ($customer) use ($search) {
                            $customer->where('phone', 'like', '%'.$search.'%')
                                ->orWhere('name', 'like', '%'.$search.'%');
                        });
                });
            })
            ->when($request->filled('branch_id'), function ($q) use ($request, $user) {
                $branch = InventoryBranchScope::requireBranchId($request->integer('branch_id'), $user);
                $q->where('branch_id', $branch->id);
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('pos.sales.index', [
            'sales' => $sales,
            'branches' => InventoryBranchScope::allowedBranches($user, activeOnly: false),
            'filters' => $request->only(['q', 'branch_id', 'status']),
            'canSell' => PosAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_POS_SELL),
            'needsBranchAssignment' => InventoryBranchScope::needsAssignment($user),
        ]);
    }

    public function show(Request $request, InventorySale $sale): View
    {
        $sale->load(['branch', 'customer', 'createdBy', 'lines.product', 'lines.variant', 'lines.serials.serial', 'serials.serial']);
        InventoryBranchScope::assertCanOperate($request->user(), $sale->branch);

        return view('pos.sales.show', [
            'sale' => $sale->loadMissing('statutoryInvoice'),
            'canCancel' => PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_CANCEL),
        ]);
    }

    public function invoice(Request $request, InventorySale $sale): View
    {
        $sale->load(['branch', 'customer', 'createdBy', 'lines.product', 'lines.variant', 'lines.serials.serial', 'serials.serial']);
        InventoryBranchScope::assertCanOperate($request->user(), $sale->branch);

        return view('pos.sales.invoice', ['sale' => $sale->loadMissing('statutoryInvoice')]);
    }

    public function cancel(Request $request, InventorySale $sale): RedirectResponse
    {
        abort_unless(
            PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_CANCEL),
            403,
        );
        InventoryBranchScope::assertCanOperate($request->user(), $sale->branch);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->sales->cancelSale($sale, $request->user(), $data['reason']);

        return redirect()->route('pos.sales.show', $sale)->with('status', 'Sale cancelled and stock restored.');
    }

    public function returnSale(Request $request, InventorySale $sale): RedirectResponse
    {
        abort_unless(
            PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_CANCEL),
            403,
        );
        InventoryBranchScope::assertCanOperate($request->user(), $sale->branch);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->sales->returnSale($sale, $request->user(), $data['reason']);

        return redirect()->route('pos.sales.show', $sale)->with('status', 'Sale returned and stock restored.');
    }
}
