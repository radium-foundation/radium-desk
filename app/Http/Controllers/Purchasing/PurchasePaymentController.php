<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchasePayment;
use App\Models\SupplierInvoice;
use App\Services\Purchasing\PurchasePaymentService;
use App\Support\Purchasing\PurchasingAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchasePaymentController extends Controller
{
    public function __construct(
        private readonly PurchasePaymentService $payments,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(PurchasingAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $payments = PurchasePayment::query()
            ->with(['vendor', 'supplierInvoice'])
            ->latest('payment_date')
            ->paginate(30);

        return view('purchasing.purchase-payments.index', ['payments' => $payments]);
    }

    public function create(SupplierInvoice $supplierInvoice): View
    {
        abort_unless(PurchasingAccess::allowsPermission(auth()->user(), RolePermissionSeeder::PERMISSION_PURCHASE_PAYMENT), 403);

        return view('purchasing.purchase-payments.create', ['invoice' => $supplierInvoice->load('vendor')]);
    }

    public function store(Request $request, SupplierInvoice $supplierInvoice): RedirectResponse
    {
        abort_unless(PurchasingAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_PURCHASE_PAYMENT), 403);

        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string', 'max:80'],
            'transaction_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->payments->record($supplierInvoice, $validated, $request->user());

        return redirect()->route('purchasing.supplier-invoices.show', $supplierInvoice)->with('status', 'Payment recorded.');
    }
}
