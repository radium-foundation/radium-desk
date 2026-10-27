<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\HistoricalInvoice\HistoricalInvoiceIndReducedCopyAudit;
use App\Services\HistoricalInvoice\HistoricalInvoiceLookupService;
use App\Services\HistoricalInvoice\IndReducedCopyDisclosure;
use App\Support\Finance\FinanceAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistoricalInvoiceController extends Controller
{
    public function __construct(
        private readonly HistoricalInvoiceLookupService $lookup,
        private readonly HistoricalInvoiceIndReducedCopyAudit $indReducedCopyAudit,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(FinanceAccess::allowsInvoices($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $query = trim((string) $request->input('q', ''));
        $result = $query === '' ? null : $this->lookup->lookup($query);

        $user = $request->user();
        if ($result !== null && $result->canReprint() && $result->isReducedIndCopy() && $user !== null) {
            $this->indReducedCopyAudit->recordView($user, $result, $request);
        }

        return view('finance.invoices.historical', [
            'query' => $query,
            'result' => $result,
            'indReducedCopyDisclaimer' => IndReducedCopyDisclosure::PRIMARY_DISCLAIMER,
        ]);
    }

    public function print(Request $request, string $invoice): View
    {
        $result = $this->lookup->lookup($invoice);
        abort_unless($result->canReprint(), 404);

        $user = $request->user();
        if ($result->isReducedIndCopy() && $user !== null) {
            $this->indReducedCopyAudit->recordPrint($user, $result, $request);
        }

        return view('finance.invoices.historical-print', [
            'result' => $result,
            'reprint' => $result->reprint,
            'indReducedCopyDisclaimer' => $result->isReducedIndCopy()
                ? IndReducedCopyDisclosure::PRIMARY_DISCLAIMER
                : null,
            'disclosedOmissions' => $result->disclosedOmissions(),
        ]);
    }
}
