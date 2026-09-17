<?php

namespace App\Http\Controllers\ServicePos;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Services\ServicePos\ServicePaymentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Support\Finance\FinanceAccess;
use App\Support\ServicePos\ServiceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ServiceOrderController extends Controller
{
    public function __construct(
        private readonly StatutoryInvoiceService $invoices,
        private readonly ServicePaymentService $payments,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                ServiceAccess::allowsSell($request->user())
                    || FinanceAccess::allowsReceivables($request->user()),
                403,
            );

            return $next($request);
        });
    }

    public function show(ServiceOrder $serviceOrder): View
    {
        $serviceOrder->load(['lines', 'customer', 'branch', 'quote', 'statutoryInvoice.items']);

        $outstanding = null;
        if ($serviceOrder->statutoryInvoice !== null) {
            $outstanding = $this->payments->outstandingForInvoice($serviceOrder->statutoryInvoice);
        }

        return view('service-pos.orders.show', [
            'order' => $serviceOrder,
            'outstanding' => $outstanding,
            'canIssueInvoice' => FinanceAccess::allowsInvoiceIssue(request()->user())
                && $serviceOrder->statutory_invoice_id === null
                && $serviceOrder->status->value !== 'cancelled',
        ]);
    }

    public function issueInvoice(ServiceOrder $serviceOrder): RedirectResponse
    {
        abort_unless(
            FinanceAccess::allowsInvoiceIssue(request()->user())
                && request()->user()?->can(RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_ISSUE),
            403,
        );

        $invoice = $this->invoices->issueFromServiceOrder($serviceOrder, request()->user());

        return redirect()
            ->route('finance.invoices.show', $invoice)
            ->with('status', 'Issued statutory invoice '.$invoice->invoice_number.'.');
    }
}
