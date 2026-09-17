<?php

namespace App\Http\Controllers\Finance;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\StatutoryInvoice;
use App\Services\ServicePos\ServicePaymentService;
use App\Support\Finance\FinanceAccess;
use Illuminate\View\View;

class ReceivablesController extends Controller
{
    public function __construct(
        private readonly ServicePaymentService $payments,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(FinanceAccess::allowsReceivables($request->user()), 403);

            return $next($request);
        });
    }

    public function index(): View
    {
        $invoices = StatutoryInvoice::query()
            ->with(['items'])
            ->where('channel', StatutoryInvoiceChannel::DeskService)
            ->where('status', StatutoryInvoiceStatus::Issued)
            ->orderByDesc('issued_at')
            ->limit(100)
            ->get()
            ->map(function (StatutoryInvoice $invoice): array {
                $allocated = round((float) $invoice->invoice_value - $this->payments->outstandingForInvoice($invoice), 2);
                $outstanding = $this->payments->outstandingForInvoice($invoice);
                $status = $this->payments->paymentStatusForInvoice($invoice);

                return [
                    'invoice' => $invoice,
                    'allocated' => $allocated,
                    'outstanding' => $outstanding,
                    'status' => $status,
                ];
            });

        return view('finance.receivables.index', [
            'rows' => $invoices,
            'canRecordPayment' => FinanceAccess::allowsPaymentRecord(request()->user()),
        ]);
    }
}
