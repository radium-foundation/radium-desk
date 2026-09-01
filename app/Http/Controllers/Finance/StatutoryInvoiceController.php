<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\StatutoryInvoice;
use App\ReadModels\Finance\StatutoryInvoiceRegisterReadModel;
use App\Services\StatutoryInvoice\StatutoryInvoiceNumberingService;
use App\Support\Finance\CsvDownload;
use App\Support\Finance\FinanceAccess;
use App\Support\Finance\ReportPeriod;
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
        $invoice->load(['items', 'branch', 'inventorySale', 'issuedBy', 'cancelledBy']);

        return view('finance.invoices.show', [
            'invoice' => $invoice,
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
