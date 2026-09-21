<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Support\Finance\CaMonthlyReportXlsxWriter;
use App\Support\Finance\CsvDownload;
use App\Support\Finance\FinanceAccess;
use App\Support\Finance\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CaMonthlyReportController extends Controller
{
    public function __construct(
        private readonly CaMonthlyStatutoryLineReadModel $readModel,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(FinanceAccess::allowsInvoices($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        $filters = $period->filters();

        return view('finance.reports.ca-monthly', [
            'filters' => $filters,
            'headers' => $this->readModel->headers(),
            'invoiceGroups' => $this->readModel->paginateInvoiceGroups($request),
            'preflight' => $this->readModel->preflight($request),
            'canExport' => FinanceAccess::allowsReportExport($request->user()),
            'dateBasis' => CaMonthlyReportDefinition::AUTHORITATIVE_DATE_COLUMN,
        ]);
    }

    public function exportXlsx(Request $request): BinaryFileResponse
    {
        abort_unless(FinanceAccess::allowsReportExport($request->user()), 403);

        $rows = $this->readModel->exportRows($request);
        $path = $this->temporaryExportPath('xlsx');

        app(CaMonthlyReportXlsxWriter::class)->write(
            $path,
            $this->readModel->headers(),
            $rows,
        );

        return response()->download(
            $path,
            'ca-monthly-report-'.$this->stamp().'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        abort_unless(FinanceAccess::allowsReportExport($request->user()), 403);

        return CsvDownload::stream(
            'ca-monthly-report-'.$this->stamp().'.csv',
            $this->readModel->headers(),
            $this->readModel->exportRows($request),
        );
    }

    private function temporaryExportPath(string $extension): string
    {
        $directory = storage_path('app/tmp/ca-monthly-report');
        File::ensureDirectoryExists($directory);

        return $directory.'/'.uniqid('ca-monthly-', true).'.'.$extension;
    }

    private function stamp(): string
    {
        return now()->timezone((string) config('app.timezone'))->format('Ymd-His');
    }
}
