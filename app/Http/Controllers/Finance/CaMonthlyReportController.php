<?php

namespace App\Http\Controllers\Finance;

use App\Enums\CaMonthlyReportExportFormat;
use App\Http\Controllers\Controller;
use App\Models\CaMonthlyReportExport;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Services\Finance\CaMonthlyReportExportGenerator;
use App\Services\Finance\CaMonthlyReportExportService;
use App\Services\Finance\CaMonthlyReportExportStorage;
use App\Services\Finance\CaMonthlyReportExportThreshold;
use App\Support\Finance\FinanceAccess;
use App\Support\Finance\ReportPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CaMonthlyReportController extends Controller
{
    public function __construct(
        private readonly CaMonthlyStatutoryLineReadModel $readModel,
        private readonly CaMonthlyReportExportService $exportService,
        private readonly CaMonthlyReportExportThreshold $exportThreshold,
        private readonly CaMonthlyReportExportStorage $exportStorage,
    ) {
        $this->middleware(function ($request, $next) {
            if ($request->routeIs('finance.reports.ca-monthly.exports.download.signed')) {
                return $next($request);
            }

            abort_unless(FinanceAccess::allowsInvoices($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        $filters = $period->filters();
        $user = $request->user();

        return view('finance.reports.ca-monthly', [
            'filters' => $filters,
            'headers' => $this->readModel->headers(),
            'invoiceGroups' => $this->readModel->paginateInvoiceGroups($request),
            'preflight' => $this->readModel->preflight($request),
            'canExport' => FinanceAccess::allowsReportExport($user),
            'dateBasis' => CaMonthlyReportDefinition::AUTHORITATIVE_DATE_COLUMN,
            'estimatedExportLines' => $this->exportThreshold->estimatedLineCount($request),
            'asyncExportThreshold' => (int) config('ca_monthly_report.sync_max_lines', 500),
            'recentExports' => $user !== null
                ? $this->exportService->recentExportsForUser($user)
                : collect(),
        ]);
    }

    public function exportXlsx(Request $request): BinaryFileResponse|RedirectResponse
    {
        abort_unless(FinanceAccess::allowsReportExport($request->user()), 403);

        if ($this->exportThreshold->requiresAsync($request)) {
            $export = $this->exportService->requestFromHttp(
                $request,
                $request->user(),
                CaMonthlyReportExportFormat::Xlsx,
            );

            return redirect()
                ->route('finance.reports.ca-monthly.index', $request->query())
                ->with('ca_export_id', $export->id)
                ->with('status', 'CA report export queued. Download will be available when generation completes.');
        }

        $path = $this->exportStorage->temporaryLocalPath(CaMonthlyReportExportFormat::Xlsx);

        app(CaMonthlyReportExportGenerator::class)->generateToPath(
            $request,
            CaMonthlyReportExportFormat::Xlsx,
            $path,
        );

        return response()->download(
            $path,
            'ca-monthly-report-'.$this->stamp().'.xlsx',
            ['Content-Type' => CaMonthlyReportExportFormat::Xlsx->mimeType()],
        )->deleteFileAfterSend(true);
    }

    public function exportCsv(Request $request): StreamedResponse|RedirectResponse
    {
        abort_unless(FinanceAccess::allowsReportExport($request->user()), 403);

        if ($this->exportThreshold->requiresAsync($request)) {
            $export = $this->exportService->requestFromHttp(
                $request,
                $request->user(),
                CaMonthlyReportExportFormat::Csv,
            );

            return redirect()
                ->route('finance.reports.ca-monthly.index', $request->query())
                ->with('ca_export_id', $export->id)
                ->with('status', 'CA report export queued. Download will be available when generation completes.');
        }

        return response()->streamDownload(function () use ($request): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, $this->readModel->headers());
            $this->readModel->streamExportRows($request, function (array $row) use ($handle): void {
                fputcsv($handle, $row);
            });
            fclose($handle);
        }, 'ca-monthly-report-'.$this->stamp().'.csv', [
            'Content-Type' => CaMonthlyReportExportFormat::Csv->mimeType(),
        ]);
    }

    public function queueExport(Request $request): RedirectResponse
    {
        abort_unless(FinanceAccess::allowsReportExport($request->user()), 403);

        $validated = $request->validate([
            'format' => 'required|in:csv,xlsx',
            'email_recipient' => 'nullable|email|max:255',
        ]);

        $format = CaMonthlyReportExportFormat::from($validated['format']);
        $export = $this->exportService->requestFromHttp(
            $request,
            $request->user(),
            $format,
            $validated['email_recipient'] ?? null,
        );

        return redirect()
            ->route('finance.reports.ca-monthly.index', $request->only(['date_from', 'date_to']))
            ->with('ca_export_id', $export->id)
            ->with('status', $export->status->label().' export requested.');
    }

    public function showExport(Request $request, CaMonthlyReportExport $export)
    {
        abort_unless($export->isOwnedBy($request->user()), 403);

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $export->id,
                'status' => $export->status->value,
                'status_label' => $export->status->label(),
                'format' => $export->format->value,
                'date_from' => $export->date_from->toDateString(),
                'date_to' => $export->date_to->toDateString(),
                'row_count' => $export->row_count,
                'failure_message' => $export->failure_message,
                'email_status' => $export->email_status?->value,
                'email_delivery_mode' => $export->email_delivery_mode?->value,
                'download_url' => $export->isDownloadable()
                    ? route('finance.reports.ca-monthly.exports.download', $export)
                    : null,
            ]);
        }

        return redirect()->route('finance.reports.ca-monthly.index', [
            'date_from' => $export->date_from->toDateString(),
            'date_to' => $export->date_to->toDateString(),
        ]);
    }

    public function downloadExport(Request $request, CaMonthlyReportExport $export): BinaryFileResponse
    {
        abort_unless(FinanceAccess::allowsReportExport($request->user()), 403);
        $this->exportService->authorizeDownload($export, $request->user());
        $this->exportService->markDownloaded($export);

        return response()->download(
            $this->exportStorage->absolutePath($export),
            $export->downloadFilename(),
            ['Content-Type' => $export->format->mimeType()],
        );
    }

    public function downloadExportSigned(Request $request, CaMonthlyReportExport $export): BinaryFileResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        abort_if($export->isExpired(), 410, 'This export has expired.');
        abort_unless($export->isDownloadable(), 404);
        abort_unless($this->exportStorage->exists($export), 404);

        return response()->download(
            $this->exportStorage->absolutePath($export),
            $export->downloadFilename(),
            ['Content-Type' => $export->format->mimeType()],
        );
    }

    public function emailExport(Request $request, CaMonthlyReportExport $export): RedirectResponse
    {
        abort_unless(FinanceAccess::allowsReportExport($request->user()), 403);
        abort_unless($export->isOwnedBy($request->user()), 403);

        $validated = $request->validate([
            'email_recipient' => 'required|email|max:255',
        ]);

        $this->exportService->queueEmail($export, $validated['email_recipient']);

        return redirect()
            ->route('finance.reports.ca-monthly.index', $request->only(['date_from', 'date_to']))
            ->with('status', 'Email delivery queued for export #'.$export->id.'.');
    }

    private function stamp(): string
    {
        return now()->timezone((string) config('app.timezone'))->format('Ymd-His');
    }
}
