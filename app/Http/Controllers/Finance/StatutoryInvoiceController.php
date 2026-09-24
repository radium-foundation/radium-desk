<?php

namespace App\Http\Controllers\Finance;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\PosHistoricalPaymentMethod;
use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\StatutoryInvoice;
use App\ReadModels\Finance\StatutoryInvoiceRegisterReadModel;
use App\Services\HistoricalInvoice\HistoricalInvoiceLookupService;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationEligibility;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use App\Services\StatutoryInvoice\StatutoryInvoiceCreditNotePolicy;
use App\Services\StatutoryInvoice\StatutoryInvoiceNumberingService;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentReadService;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentReconciliationService;
use App\Services\StatutoryInvoice\StatutoryInvoiceRefundReviewService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Support\Finance\CsvDownload;
use App\Support\Finance\FinanceAccess;
use App\Support\Finance\ReportPeriod;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Database\Seeders\RolePermissionSeeder;
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
            if (FinanceAccess::allowsInvoices($request->user())) {
                return $next($request);
            }

            $invoice = $request->route('invoice');
            abort_unless(
                $invoice instanceof StatutoryInvoice
                    && HardwareFulfilmentAccess::allowsLinkedInvoice($request->user(), $invoice),
                403,
            );

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
        $invoice->load(['items', 'branch', 'inventorySale', 'issuedBy', 'cancelledBy', 'document', 'eInvoiceRecord', 'cancellation']);

        $eInvoiceRecord = $invoice->eInvoiceRecord;
        $canReevaluateEinvoice = FinanceAccess::allowsInvoiceIssue(request()->user())
            && request()->user()?->can(RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_ISSUE)
            && $eInvoiceRecord !== null
            && $eInvoiceRecord->status === EInvoiceRecordStatus::Skipped->value
            && ! $eInvoiceRecord->hasIssuedIrn();

        $canCancelInvoice = FinanceAccess::allowsInvoiceCancel(request()->user())
            && $invoice->status === StatutoryInvoiceStatus::Issued;

        $cancellationConsequences = [];
        if ($canCancelInvoice) {
            $eligibility = app(StatutoryInvoiceCancellationEligibility::class);
            $creditNotes = app(StatutoryInvoiceCreditNotePolicy::class);

            if ($eligibility->irnCancellationRequired($invoice)) {
                $cancellationConsequences[] = 'A submitted IRN must be cancelled before the statutory invoice can be cancelled.';
            }

            if ($eligibility->inventoryReversalApplicable($invoice)) {
                $cancellationConsequences[] = 'Linked POS inventory and the posted POS finance journal will be reversed.';
            }

            $cancellationConsequences[] = $creditNotes->requirementSummary();
            $cancellationConsequences[] = 'Customer payment is not refunded automatically. Finance must review and create a separate refund request when applicable.';
        }

        $refundReview = null;
        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            $refundReview = app(StatutoryInvoiceRefundReviewService::class)->snapshot($invoice);
        }

        $paymentSummary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice);
        $reconciliationService = app(StatutoryInvoicePaymentReconciliationService::class);

        return view('finance.invoices.show', [
            'invoice' => $invoice,
            'canReevaluateEinvoice' => $canReevaluateEinvoice,
            'canCancelInvoice' => $canCancelInvoice,
            'cancellationConsequences' => $cancellationConsequences,
            'refundReview' => $refundReview,
            'canRequestRefund' => $refundReview?->allowsExplicitRefundRequest(request()->user()) ?? false,
            'paymentSummary' => $paymentSummary,
            'canRecordPayment' => FinanceAccess::allowsPaymentRecord(request()->user())
                && $paymentSummary->allocationBacked
                && $paymentSummary->amountOutstanding > 0
                && $invoice->status !== StatutoryInvoiceStatus::Cancelled
                && ! $paymentSummary->reconciliationRequired
                && $reconciliationService->allowsAdditionalPaymentRecording($invoice),
            'canBackfillPayment' => FinanceAccess::allowsPaymentBackfill(request()->user())
                && $reconciliationService->allowsBackfill($invoice),
            'backfillPaymentMethods' => PosHistoricalPaymentMethod::backfillCases(),
        ]);
    }

    public function cancel(Request $request, StatutoryInvoice $invoice): RedirectResponse
    {
        abort_unless(FinanceAccess::allowsInvoiceCancel($request->user()), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'confirm' => ['accepted'],
        ]);

        $result = app(StatutoryInvoiceCancellationOrchestrator::class)->cancel(
            invoice: $invoice,
            actor: $request->user(),
            reason: $validated['reason'],
            idempotencyKey: StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$invoice->id,
        );

        $message = $result->idempotent
            ? 'This statutory invoice was already cancelled.'
            : 'Statutory invoice cancelled successfully.';

        return redirect()
            ->route('finance.invoices.show', $invoice)
            ->with('status', $message);
    }

    public function reevaluateEinvoice(Request $request, StatutoryInvoice $invoice): RedirectResponse
    {
        abort_unless(
            FinanceAccess::allowsInvoiceIssue($request->user())
                && $request->user()?->can(RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_ISSUE),
            403,
        );

        app(StatutoryInvoiceService::class)->reevaluateEinvoiceEligibility($invoice->fresh(['items']));

        return redirect()
            ->route('finance.invoices.show', $invoice)
            ->with('status', 'E-invoice eligibility re-evaluated. This does not submit to the IRP automatically.');
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
