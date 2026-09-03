<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StatutoryInvoiceIssueController extends Controller
{
    public function __construct(
        private readonly StatutoryInvoiceService $invoices,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(FinanceAccess::allowsInvoices($request->user()), 403);

            return $next($request);
        });
    }

    public function pending(): View
    {
        $orders = CommerceOrder::query()
            ->whereNull('statutory_invoice_id')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('finance.invoices.pending', [
            'orders' => $orders,
            'canIssue' => FinanceAccess::allowsInvoiceIssue(request()->user()),
        ]);
    }

    public function show(CommerceOrder $order): View
    {
        $order->load(['items', 'statutoryInvoice']);

        return view('finance.invoices.commerce-order', [
            'order' => $order,
            'canIssue' => FinanceAccess::allowsInvoiceIssue(request()->user()),
        ]);
    }

    public function issue(Request $request, CommerceOrder $order): RedirectResponse
    {
        abort_unless(
            FinanceAccess::allowsInvoiceIssue($request->user())
                && $request->user()?->can(RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_ISSUE),
            403,
        );

        try {
            $invoice = $this->invoices->issueFromCommerceOrder($order, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return redirect()
            ->route('finance.invoices.commerce-orders.show', $order)
            ->with('status', 'Issued statutory invoice '.$invoice->invoice_number.'.');
    }
}
