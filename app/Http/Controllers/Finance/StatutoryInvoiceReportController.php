<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\ReadModels\Finance\StatutoryInvoiceRegisterReadModel;
use App\Support\Finance\FinanceAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StatutoryInvoiceReportController extends Controller
{
    public function __construct(
        private readonly StatutoryInvoiceRegisterReadModel $register,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                FinanceAccess::allowsGstReports($request->user())
                    || FinanceAccess::allowsSalesReports($request->user()),
                403,
            );

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $gst = FinanceAccess::allowsGstReports($request->user())
            ? $this->register->gstSummary($request)
            : [];
        $sales = FinanceAccess::allowsSalesReports($request->user())
            ? $this->register->salesByChannel($request)
            : [];

        return view('finance.reports.index', [
            'gstSummary' => $gst,
            'salesByChannel' => $sales,
            'canExport' => FinanceAccess::allowsReportExport($request->user()),
            'canGst' => FinanceAccess::allowsGstReports($request->user()),
            'canSales' => FinanceAccess::allowsSalesReports($request->user()),
        ]);
    }
}
