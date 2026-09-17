<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchasingDocument;
use App\Models\SupplierInvoice;
use App\Services\Purchasing\PurchasingDocumentService;
use App\Services\Purchasing\SupplierInvoiceService;
use App\Support\Purchasing\PurchasingAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierInvoiceController extends Controller
{
    public function __construct(
        private readonly SupplierInvoiceService $invoices,
        private readonly PurchasingDocumentService $documents,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(PurchasingAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $invoices = SupplierInvoice::query()
            ->with(['vendor', 'purchaseOrder'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = $request->string('q')->trim()->toString();
                $query->where('supplier_invoice_number', 'like', '%'.$search.'%');
            })
            ->latest('invoice_date')
            ->paginate(30)
            ->withQueryString();

        return view('purchasing.supplier-invoices.index', [
            'invoices' => $invoices,
            'filters' => $request->only(['q']),
        ]);
    }

    public function create(PurchaseOrder $purchaseOrder): View
    {
        abort_unless(PurchasingAccess::allowsPermission(auth()->user(), RolePermissionSeeder::PERMISSION_PURCHASE_INVOICE), 403);

        return view('purchasing.supplier-invoices.create', [
            'purchaseOrder' => $purchaseOrder->load('vendor'),
            'receipts' => $purchaseOrder->goodsReceipts()->latest('id')->get(),
        ]);
    }

    public function store(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless(PurchasingAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_PURCHASE_INVOICE), 403);

        $validated = $request->validate([
            'goods_receipt_id' => ['nullable', 'exists:goods_receipts,id'],
            'supplier_invoice_number' => ['required', 'string', 'max:120'],
            'invoice_date' => ['required', 'date'],
            'invoice_amount' => ['required', 'numeric', 'min:0'],
            'taxable_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'document' => ['nullable', 'file', 'max:10240'],
        ]);

        $receipt = filled($validated['goods_receipt_id'] ?? null)
            ? GoodsReceipt::query()->find($validated['goods_receipt_id'])
            : null;

        $invoice = $this->invoices->record(
            $purchaseOrder,
            $validated,
            $request->user(),
            $receipt,
            $request->file('document'),
        );

        return redirect()->route('purchasing.supplier-invoices.show', $invoice)->with('status', 'Supplier invoice recorded.');
    }

    public function show(SupplierInvoice $supplierInvoice): View
    {
        $supplierInvoice->load(['vendor', 'purchaseOrder', 'goodsReceipt', 'payments', 'documents']);

        return view('purchasing.supplier-invoices.show', ['invoice' => $supplierInvoice]);
    }

    public function downloadDocument(SupplierInvoice $supplierInvoice, PurchasingDocument $document)
    {
        abort_unless(
            $document->related_type === SupplierInvoice::class && (int) $document->related_id === $supplierInvoice->id,
            404,
        );

        return $this->documents->downloadResponse($document);
    }
}
