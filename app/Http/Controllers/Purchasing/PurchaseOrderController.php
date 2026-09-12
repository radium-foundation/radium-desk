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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $poNumber = $request->string('q')->trim()->toString();
        $status = $request->string('status')->trim()->toString();
        $vendorId = $request->filled('vendor_id') && Vendor::query()->whereKey($request->integer('vendor_id'))->exists()
            ? $request->integer('vendor_id')
            : null;

        $orders = PurchaseOrder::query()
            ->with(['vendor', 'branch'])
            ->when($poNumber !== '', fn ($query) => $query->where('po_number', 'like', '%'.$poNumber.'%'))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($vendorId !== null, fn ($query) => $query->where('vendor_id', $vendorId))
            ->latest('po_date')
            ->paginate(30)
            ->withQueryString();

        return view('purchasing.purchase-orders.index', [
            'orders' => $orders,
            'filters' => [
                'q' => $poNumber !== '' ? $poNumber : null,
                'status' => $status !== '' ? $status : null,
                'vendor_id' => $vendorId,
            ],
            'selectedVendor' => $vendorId !== null ? Vendor::query()->find($vendorId) : null,
            'searchVendorsUrl' => route('purchasing.vendors.search'),
        ]);
    }

    public function create(): View
    {
        abort_unless(PurchasingAccess::allowsPermission(auth()->user(), RolePermissionSeeder::PERMISSION_PURCHASE_CREATE), 403);

        return view('purchasing.purchase-orders.create', [
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('name')->get(),
            'searchProductsUrl' => route('purchasing.products.search'),
            'searchVendorsUrl' => route('purchasing.vendors.search'),
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        abort_unless(PurchasingAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_PURCHASE_CREATE), 403);

        $q = $request->string('q')->trim()->toString();
        if ($q === '') {
            return response()->json(['products' => []]);
        }

        $products = InventoryProduct::query()
            ->where('is_active', true)
            ->where(function ($query) use ($q) {
                $query->where('sku', 'like', '%'.$q.'%')
                    ->orWhere('name', 'like', '%'.$q.'%');
            })
            ->orderBy('sku')
            ->limit(20)
            ->get(['id', 'sku', 'name', 'is_serialized', 'gst_percentage', 'unit_cost']);

        return response()->json([
            'products' => $products->map(static fn (InventoryProduct $product): array => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'is_serialized' => $product->is_serialized,
                'gst_percentage' => (float) $product->gst_percentage,
                'unit_cost' => (float) ($product->unit_cost ?? 0),
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(PurchasingAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_PURCHASE_CREATE), 403);

        $validated = $request->validate([
            'po_number' => ['prohibited'],
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
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

        $lines = array_values(array_filter(
            $validated['lines'],
            static fn (array $line): bool => (int) ($line['quantity'] ?? 0) >= 1,
        ));

        if ($lines === []) {
            return back()
                ->withInput()
                ->withErrors(['lines' => 'Add at least one product line with quantity at least 1.']);
        }

        $po = $this->purchaseOrders->createDraft($validated, $lines, $request->user());

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
