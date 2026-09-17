<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchasingReconciliationService;
use App\Support\Purchasing\PurchasingAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsReceiptService $receipts,
        private readonly PurchasingReconciliationService $reconciliation,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(PurchasingAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $receipts = GoodsReceipt::query()
            ->with(['vendor', 'purchaseOrder'])
            ->when($request->filled('q'), fn ($q) => $q->where('receipt_number', 'like', '%'.$request->string('q')->trim().'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->latest('receipt_date')
            ->paginate(30)
            ->withQueryString();

        return view('purchasing.goods-receipts.index', [
            'receipts' => $receipts,
            'filters' => $request->only(['q', 'status']),
        ]);
    }

    public function create(PurchaseOrder $purchaseOrder): View
    {
        abort_unless(PurchasingAccess::allowsPermission(auth()->user(), RolePermissionSeeder::PERMISSION_PURCHASE_RECEIVE), 403);

        $purchaseOrder->load('items.product');

        return view('purchasing.goods-receipts.create', ['purchaseOrder' => $purchaseOrder]);
    }

    public function store(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless(PurchasingAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_PURCHASE_RECEIVE), 403);

        $validated = $request->validate([
            'receipt_date' => ['required', 'date'],
            'supplier_challan_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_item_id' => ['required', 'exists:purchase_order_items,id'],
            'lines.*.product_id' => ['required', 'exists:inventory_products,id'],
            'lines.*.quantity_received' => ['required', 'integer', 'min:1'],
            'lines.*.quantity_damaged' => ['nullable', 'integer', 'min:0'],
            'serials' => ['nullable', 'array'],
            'serials.*' => ['nullable', 'string'],
        ]);

        $receipt = $this->receipts->createDraft(
            $purchaseOrder,
            $validated['lines'],
            $request->user(),
            $validated['receipt_date'],
            $validated['supplier_challan_reference'] ?? null,
            $validated['notes'] ?? null,
        );

        foreach ($validated['lines'] as $index => $line) {
            $serialText = $validated['serials'][$index] ?? null;
            if (! filled($serialText)) {
                continue;
            }

            $item = $receipt->items->firstWhere('purchase_order_item_id', (int) $line['purchase_order_item_id']);
            if ($item !== null) {
                $this->receipts->captureSerials($item, $serialText, $request->user());
            }
        }

        $this->receipts->submitForConfirmation($receipt->fresh(['items.product', 'items.serials']), $request->user());

        return redirect()->route('purchasing.goods-receipts.show', $receipt)->with('status', 'Goods receipt recorded and pending confirmation.');
    }

    public function show(GoodsReceipt $goodsReceipt): View
    {
        $goodsReceipt->load(['vendor', 'purchaseOrder.items', 'items.product', 'items.serials', 'supplierInvoices']);

        return view('purchasing.goods-receipts.show', [
            'goodsReceipt' => $goodsReceipt,
            'summary' => $this->reconciliation->buildSummary($goodsReceipt),
        ]);
    }

    public function complete(GoodsReceipt $goodsReceipt): RedirectResponse
    {
        abort_unless(PurchasingAccess::allowsPermission(auth()->user(), RolePermissionSeeder::PERMISSION_PURCHASE_RECEIVE), 403);

        $this->reconciliation->completeReceiving($goodsReceipt, auth()->user());

        return back()->with('status', 'Receiving completed. Eligible stock is now available.');
    }
}
