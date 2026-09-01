<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\ReadModels\Finance\ChannelOrderMonthEndReadModel;
use App\ReadModels\Finance\StatutoryInvoiceRegisterReadModel;
use App\Support\Finance\CsvDownload;
use App\Support\Finance\FinanceAccess;
use App\Support\Finance\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatutoryInvoiceReportController extends Controller
{
    /**
     * @var list<string>
     */
    public const EXPORTS = [
        'register',
        'lines',
        'gst',
        'sales',
        'collections',
        'cancelled',
        'channel_orders',
        'summary',
    ];

    public function __construct(
        private readonly StatutoryInvoiceRegisterReadModel $register,
        private readonly ChannelOrderMonthEndReadModel $channelOrders,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                FinanceAccess::allowsGstReports($request->user())
                    || FinanceAccess::allowsSalesReports($request->user())
                    || FinanceAccess::allowsInvoices($request->user()),
                403,
            );

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $canGst = FinanceAccess::allowsGstReports($user);
        $canSales = FinanceAccess::allowsSalesReports($user);
        $canInvoices = FinanceAccess::allowsInvoices($user);
        $invoiceSummary = ($canGst || $canInvoices || $canSales)
            ? $this->register->monthEndSummary($request)
            : [];
        $channelSummary = $canSales
            ? $this->channelOrders->eligibilitySummary($request)
            : [];

        return view('finance.reports.index', [
            'filters' => array_merge(
                ReportPeriod::fromRequest($request)->filters(),
                $request->only(['q', 'channel', 'status']),
            ),
            'summary' => array_merge($invoiceSummary, $this->prefixed($channelSummary, 'channel_orders_')),
            'gstSummary' => $canGst ? $this->register->gstSummary($request) : [],
            'salesByDateAndChannel' => $canSales ? $this->register->salesByDateAndChannel($request) : [],
            'cancelledInvoices' => ($canInvoices || $canGst) ? $this->register->cancelledInvoices($request) : [],
            'channelOrders' => $canSales ? $this->channelOrders->paginate($request) : null,
            'canExport' => FinanceAccess::allowsReportExport($user),
            'canGst' => $canGst,
            'canSales' => $canSales,
            'canInvoices' => $canInvoices,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(FinanceAccess::allowsReportExport($request->user()), 403);

        $report = $request->string('report')->trim()->toString();
        if ($report === '') {
            $report = 'register';
        }
        abort_unless(in_array($report, self::EXPORTS, true), 404);

        $user = $request->user();
        $this->assertExportPermission($report, $user);

        [$headers, $rows] = $this->dataset($report, $request);
        $filename = 'desk-month-end-'.$report.'-'.$this->stamp().'.csv';

        return CsvDownload::stream($filename, $headers, $rows);
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function dataset(string $report, Request $request): array
    {
        return match ($report) {
            'register' => [
                $this->register->registerHeaders(),
                array_map(
                    fn ($invoice): array => $this->register->registerRow($invoice),
                    $this->register->exportRows($request),
                ),
            ],
            'lines' => [$this->register->lineHeaders(), $this->register->lineRows($request)],
            'gst' => [$this->register->gstHeaders(), $this->register->gstRows($request)],
            'sales' => [$this->register->salesHeaders(), $this->register->salesRows($request)],
            'collections' => [
                $this->register->collectionHeaders(),
                array_merge(
                    $this->register->collectionRows($request),
                    $this->channelOrders->collectionRows($request),
                ),
            ],
            'cancelled' => [
                $this->register->registerHeaders(),
                array_map(
                    fn ($invoice): array => $this->register->registerRow($invoice),
                    $this->register->cancelledInvoices($request),
                ),
            ],
            'channel_orders' => [$this->channelOrders->orderHeaders(), $this->channelOrders->orderRows($request)],
            'summary' => [
                $this->register->summaryHeaders(),
                $this->register->summaryRows($this->combinedSummary($request)),
            ],
            default => abort(404),
        };
    }

    /**
     * @return array<string, float|int>
     */
    private function combinedSummary(Request $request): array
    {
        return array_merge(
            $this->register->monthEndSummary($request),
            $this->prefixed($this->channelOrders->eligibilitySummary($request), 'channel_orders_'),
        );
    }

    private function assertExportPermission(string $report, mixed $user): void
    {
        $allowed = match ($report) {
            'register', 'lines', 'cancelled' => FinanceAccess::allowsInvoices($user),
            'gst' => FinanceAccess::allowsGstReports($user),
            'sales', 'channel_orders' => FinanceAccess::allowsSalesReports($user),
            'collections', 'summary' => FinanceAccess::allowsInvoices($user)
                || FinanceAccess::allowsGstReports($user)
                || FinanceAccess::allowsSalesReports($user),
            default => false,
        };

        abort_unless($allowed, 403);
    }

    /**
     * @param  array<string, int>  $summary
     * @return array<string, int>
     */
    private function prefixed(array $summary, string $prefix): array
    {
        $out = [];
        foreach ($summary as $key => $value) {
            $out[$prefix.$key] = $value;
        }

        return $out;
    }

    private function stamp(): string
    {
        return now()->timezone((string) config('app.timezone'))->format('Ymd-His');
    }
}
