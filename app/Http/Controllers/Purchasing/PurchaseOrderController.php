<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchasingAuditLog;
use App\Models\Vendor;
use App\Services\Purchasing\PurchaseOrderService;
use App\Support\Purchasing\PurchasingAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(PurchasingAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $orders = PurchaseOrder::query()
            ->with(['vendor', 'branch'])
            ->when($request->filled('q'), fn ($q) => $q->where('po_number', 'like', '%'.$request->string('q')->trim().'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->integer('vendor_id')))
            ->latest('po_date')
            ->paginate(30)
            ->withQueryString();

        return view('purchasing.purchase-orders.index', [
            'orders' => $orders,
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('business_name')->get(),
            'filters' => $request->only(['q', 'status', 'vendor_id']),
        ]);
    }

    public function create(): View
    {
        abort_unless(PurchasingAccess::allowsPermission(auth()->user(), RolePermissionSeeder::PERMISSION_PURCHASE_CREATE), 403);

        return view('purchasing.purchase-orders.create', [
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('business_name')->get(),
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('name')->get(),
            'products' => InventoryProduct::query()->where('is_active', true)->orderBy('sku')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(PurchasingAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_PURCHASE_CREATE), 403);

        $validated = $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'],
            'branch_id' => ['required', 'exists:inventory_branches,id'],
            'po_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:inventory_products,id'],
            'lines.*.variant_id' => ['nullable', 'exists:inventory_product_variants,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $po = $this->purchaseOrders->createDraft($validated, $validated['lines'], $request->user());

        return redirect()->route('purchasing.purchase-orders.show', $po)->with('status', 'Purchase order created.');
    }

    public function show(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load(['vendor', 'branch', 'items.product', 'goodsReceipts', 'supplierInvoices.payments']);

        $audit = PurchasingAuditLog::query()
            ->where('auditable_type', PurchaseOrder::class)
            ->where('auditable_id', $purchaseOrder->id)
            ->latest('id')
            ->limit(20)
            ->get();

        return view('purchasing.purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder,
            'audit' => $audit,
        ]);
    }

    public function send(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless(PurchasingAccess::allowsPermission(auth()->user(), RolePermissionSeeder::PERMISSION_PURCHASE_EDIT), 403);

        $this->purchaseOrders->send($purchaseOrder, auth()->user());

        return back()->with('status', 'Purchase order sent.');
    }

    public function cancel(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless(PurchasingAccess::allowsPermission(auth()->user(), RolePermissionSeeder::PERMISSION_PURCHASE_EDIT), 403);

        $this->purchaseOrders->cancel($purchaseOrder, auth()->user());

        return back()->with('status', 'Purchase order cancelled.');
    }
}
