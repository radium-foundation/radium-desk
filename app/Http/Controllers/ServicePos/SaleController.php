<?php

namespace App\Http\Controllers\ServicePos;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Support\Inventory\InventoryBranchScope;
use App\Support\ServicePos\ServiceAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(ServiceAccess::allowsSell($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $search = $request->string('q')->trim()->toString();

        $orders = InventoryBranchScope::constrain(
            ServiceOrder::query()->with(['branch', 'customer', 'quote', 'statutoryInvoice']),
            $user,
        )
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('order_number', 'like', '%'.$search.'%')
                        ->orWhere('payment_reference', 'like', '%'.$search.'%')
                        ->orWhereHas('quote', fn ($quote) => $quote->where('quote_number', 'like', '%'.$search.'%'))
                        ->orWhereHas('statutoryInvoice', fn ($invoice) => $invoice->where('invoice_number', 'like', '%'.$search.'%'))
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

        return view('service-pos.sales.index', [
            'orders' => $orders,
            'branches' => InventoryBranchScope::allowedBranches($user, activeOnly: false),
            'filters' => $request->only(['q', 'branch_id', 'status']),
            'needsBranchAssignment' => InventoryBranchScope::needsAssignment($user),
        ]);
    }
}
