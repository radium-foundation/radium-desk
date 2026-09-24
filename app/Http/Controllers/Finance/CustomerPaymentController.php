<?php

namespace App\Http\Controllers\Finance;

use App\Enums\StatutoryInvoiceChannel;
use App\Http\Controllers\Controller;
use App\Models\InventoryCustomer;
use App\Models\StatutoryInvoice;
use App\Services\ServicePos\ServicePaymentService;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerPaymentController extends Controller
{
    public function __construct(
        private readonly ServicePaymentService $payments,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                FinanceAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_FINANCE_PAYMENTS_VIEW,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $invoiceId = $request->integer('invoice_id');
        $selectedInvoice = $invoiceId > 0
            ? StatutoryInvoice::query()
                ->whereIn('channel', [
                    StatutoryInvoiceChannel::DeskService,
                    StatutoryInvoiceChannel::DeskPos,
                ])
                ->with('inventorySale.customer')
                ->find($invoiceId)
            : null;

        $outstanding = $selectedInvoice !== null
            ? $this->payments->outstandingForInvoice($selectedInvoice)
            : null;

        $selectedCustomerId = null;
        if ($selectedInvoice !== null) {
            $selectedCustomerId = $selectedInvoice->inventorySale?->customer_id;
            if ($selectedCustomerId === null && $selectedInvoice->buyer_phone) {
                $selectedCustomerId = InventoryCustomer::query()
                    ->where('phone', $selectedInvoice->buyer_phone)
                    ->value('id');
            }
        }

        $payableInvoices = StatutoryInvoice::query()
            ->whereIn('channel', [
                StatutoryInvoiceChannel::DeskService,
                StatutoryInvoiceChannel::DeskPos,
            ])
            ->orderByDesc('issued_at')
            ->limit(100)
            ->get();

        return view('finance.payments.index', [
            'payableInvoices' => $payableInvoices,
            'selectedInvoice' => $selectedInvoice,
            'selectedCustomerId' => $selectedCustomerId,
            'outstanding' => $outstanding,
            'canRecord' => FinanceAccess::allowsPaymentRecord($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(FinanceAccess::allowsPaymentRecord($request->user()), 403);

        $data = $request->validate([
            'customer_id' => ['required', 'exists:inventory_customers,id'],
            'statutory_invoice_id' => ['required', 'exists:statutory_invoices,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:128'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'payment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $customer = InventoryCustomer::query()->findOrFail($data['customer_id']);
        $invoice = StatutoryInvoice::query()->findOrFail($data['statutory_invoice_id']);

        try {
            $this->payments->assertInvoiceSupportsPaymentRecording($invoice);
            $this->payments->assertNormalPaymentRecordingAllowed($invoice);

            $payment = $this->payments->recordPayment(
                customer: $customer,
                amount: (float) $data['amount'],
                method: $data['method'],
                paymentDate: new \DateTimeImmutable($data['payment_date']),
                actor: $request->user(),
                reference: $data['reference'] ?? null,
                notes: $data['notes'] ?? null,
                idempotencyKey: $data['idempotency_key'] ?? null,
                bankName: $data['bank_name'] ?? null,
                bankBranch: $data['bank_branch'] ?? null,
            );

            $this->payments->allocatePayment(
                payment: $payment,
                invoice: $invoice,
                amount: (float) $data['amount'],
                actor: $request->user(),
                idempotencyKey: ($data['idempotency_key'] ?? null) !== null
                    ? 'alloc:'.$data['idempotency_key']
                    : null,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        $remaining = $this->payments->outstandingForInvoice($invoice->fresh() ?? $invoice);

        return redirect()
            ->route('finance.payments.index', ['invoice_id' => $invoice->id])
            ->with('status', 'Payment recorded. Outstanding: ₹'.number_format($remaining, 2));
    }
}
