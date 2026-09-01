<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\StatutoryInvoice;
use App\ReadModels\Finance\StatutoryInvoiceRegisterReadModel;
use App\Services\StatutoryInvoice\StatutoryInvoiceNumberingService;
use App\Support\Finance\FinanceAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatutoryInvoiceController extends Controller
{
    public function __construct(
        private readonly StatutoryInvoiceRegisterReadModel $register,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(FinanceAccess::allowsInvoices($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        return view('finance.invoices.index', [
            'invoices' => $this->register->paginate($request),
            'filters' => $request->only(['q', 'channel', 'status']),
            'canExport' => FinanceAccess::allowsReportExport($request->user()),
            'numberingConfigured' => app(StatutoryInvoiceNumberingService::class)->isConfigured(),
        ]);
    }

    public function show(StatutoryInvoice $invoice): View
    {
        $invoice->load(['items', 'branch', 'inventorySale', 'issuedBy']);

        return view('finance.invoices.show', [
            'invoice' => $invoice,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(FinanceAccess::allowsReportExport($request->user()), 403);

        $filename = 'statutory-invoice-register-'.now()->timezone(config('app.timezone'))->format('Ymd-His').'.csv';
        $headers = $this->register->registerHeaders();
        $rows = $this->register->exportRows($request);

        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            fputcsv($handle, $headers);
            foreach ($rows as $invoice) {
                fputcsv($handle, $this->register->registerRow($invoice));
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
