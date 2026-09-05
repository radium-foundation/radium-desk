<?php

namespace App\Http\Controllers\Finance;

use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\StatutoryInvoice;
use App\ReadModels\Finance\StatutoryInvoiceRegisterReadModel;
use App\Services\HistoricalInvoice\HistoricalInvoiceLookupService;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceNumberingService;
use App\Support\Finance\CsvDownload;
use App\Support\Finance\FinanceAccess;
use App\Support\Finance\ReportPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
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

    public function index(Request $request): View|RedirectResponse
    {
        $query = trim((string) $request->input('q', ''));
        if (HistoricalInvoiceLookupService::shouldOfferHistoricalLookup($query)) {
            return redirect()->route('finance.invoices.historical', [
                'q' => HistoricalInvoiceLookupService::normalizeLookupQuery($query),
            ]);
        }

        $period = ReportPeriod::fromRequest($request);

        return view('finance.invoices.index', [
            'invoices' => $this->register->paginate($request),
            'filters' => array_merge(
                $period->filters(),
                $request->only(['q', 'channel', 'status']),
            ),
            'canExport' => FinanceAccess::allowsReportExport($request->user()),
            'numberingConfigured' => app(StatutoryInvoiceNumberingService::class)->isConfigured(),
        ]);
    }

    public function show(StatutoryInvoice $invoice): View
    {
        $invoice->load(['items', 'branch', 'inventorySale', 'issuedBy', 'cancelledBy', 'document']);

        return view('finance.invoices.show', [
            'invoice' => $invoice,
        ]);
    }

    public function download(StatutoryInvoice $invoice): Response
    {
        $invoice->loadMissing('document');
        $document = $invoice->document;
        if ($document === null || $document->status !== StatutoryInvoiceDocumentStatus::Generated) {
            try {
                $document = app(StatutoryDocumentService::class)->generate($invoice);
            } catch (\Throwable) {
                abort(404);
            }
        }

        abort_unless(
            $document !== null && $document->status === StatutoryInvoiceDocumentStatus::Generated,
            404,
        );

        $binary = app(StatutoryDocumentService::class)->binary($document);

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->invoice_number.'.pdf"',
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(FinanceAccess::allowsReportExport($request->user()), 403);

        $filename = 'statutory-invoice-register-'.$this->stamp().'.csv';

        return CsvDownload::stream(
            $filename,
            $this->register->registerHeaders(),
            array_map(
                fn ($invoice): array => $this->register->registerRow($invoice),
                $this->register->exportRows($request),
            ),
        );
    }

    private function stamp(): string
    {
        return now()->timezone((string) config('app.timezone'))->format('Ymd-His');
    }
}
