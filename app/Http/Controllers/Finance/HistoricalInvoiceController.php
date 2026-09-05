<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\HistoricalInvoice\HistoricalInvoiceLookupService;
use App\Support\Finance\FinanceAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistoricalInvoiceController extends Controller
{
    public function __construct(
        private readonly HistoricalInvoiceLookupService $lookup,
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

        return view('finance.invoices.historical', [
            'query' => $query,
            'result' => $result,
        ]);
    }

    public function print(Request $request, string $invoice): View
    {
        $result = $this->lookup->lookup($invoice);
        abort_unless($result->canReprint(), 404);

        return view('finance.invoices.historical-print', [
            'result' => $result,
            'reprint' => $result->reprint,
        ]);
    }
}
