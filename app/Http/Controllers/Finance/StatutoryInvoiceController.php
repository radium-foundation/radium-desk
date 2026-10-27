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
use App\Enums\StatutoryInvoiceCancellationOperationStatus;
use App\Models\StatutoryInvoiceCancellation;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceCancellationResult;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationAdminPresentation;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationEligibility;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use App\Services\StatutoryInvoice\StatutoryInvoiceCreditNotePolicy;
use App\Services\StatutoryInvoice\StatutoryInvoiceNumberingService;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentReadService;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentReconciliationService;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancelledRecoveryService;
use App\Services\StatutoryInvoice\StatutoryInvoiceRefundReviewService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Support\Finance\CsvDownload;
use App\Support\Finance\FinanceAccess;
use App\Support\Finance\ReportPeriod;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        if ($query !== '') {
            $historicalLookup = app(HistoricalInvoiceLookupService::class);
            if (
                HistoricalInvoiceLookupService::shouldOfferHistoricalLookup($query)
                || $historicalLookup->shouldExposeHistoricalFinanceFallback($query)
            ) {
                return redirect()->route('finance.invoices.historical', [
                    'q' => HistoricalInvoiceLookupService::normalizeLookupQuery($query),
                ]);
            }
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

        $cancellationAdmin = app(StatutoryInvoiceCancellationAdminPresentation::class)
            ->forInvoice($invoice, request()->user());

        $canCancelInvoice = $cancellationAdmin->showCancellationForm
            && $cancellationAdmin->maySubmitCancellationRequest;

        $refundReview = null;
        $cancelledRecovery = null;
        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            $refundReview = app(StatutoryInvoiceRefundReviewService::class)->snapshot($invoice);
            $cancelledRecovery = app(StatutoryInvoiceCancelledRecoveryService::class)->assess($invoice);
        }

        $paymentSummary = app(StatutoryInvoicePaymentReadService::class)->summary($invoice);
        $reconciliationService = app(StatutoryInvoicePaymentReconciliationService::class);

        return view('finance.invoices.show', [
            'invoice' => $invoice,
            'canReevaluateEinvoice' => $canReevaluateEinvoice,
            'canCancelInvoice' => $canCancelInvoice,
            'cancellationAdmin' => $cancellationAdmin,
            'refundReview' => $refundReview,
            'cancelledRecovery' => $cancelledRecovery,
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

        $invoice->load(['items', 'inventorySale', 'eInvoiceRecord', 'cancellation']);

        try {
            app(StatutoryInvoiceCancellationEligibility::class)->assertCanCancel($invoice);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('finance.invoices.show', $invoice)
                ->withErrors($exception->errors());
        }

        if ($invoice->cancellation?->requiresManualReview()) {
            return redirect()
                ->route('finance.invoices.show', $invoice)
                ->withErrors([
                    'invoice' => 'This cancellation requires manual review before it can continue.',
                ]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'confirm' => ['accepted'],
        ]);

        try {
            $result = app(StatutoryInvoiceCancellationOrchestrator::class)->cancel(
                invoice: $invoice,
                actor: $request->user(),
                reason: $validated['reason'],
                idempotencyKey: StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$invoice->id,
            );
        } catch (ValidationException $exception) {
            $redirect = redirect()
                ->route('finance.invoices.show', $invoice)
                ->withErrors($exception->errors())
                ->withInput();

            if (array_key_exists('irn', $exception->errors())) {
                $redirect
                    ->with(
                        'status',
                        'Cancellation request recorded but IRN outcome is not confirmed. '
                        .'This does not mean the IRN is cancelled. Reconcile provider status before retrying.',
                    )
                    ->with('status_level', 'warning');
            }

            return $redirect;
        }

        $operation = StatutoryInvoiceCancellation::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();

        [$message, $level] = $this->cancellationResponseMessage(
            $result,
            $invoice->fresh(['cancellation']),
            $operation,
        );

        return redirect()
            ->route('finance.invoices.show', $invoice)
            ->with('status', $message)
            ->with('status_level', $level);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function cancellationResponseMessage(
        StatutoryInvoiceCancellationResult $result,
        StatutoryInvoice $invoice,
        ?StatutoryInvoiceCancellation $operation,
    ): array {
        $correlation = $operation?->correlation_id;
        $correlationSuffix = $correlation !== null && $correlation !== ''
            ? ' Reference: '.$correlation.'.'
            : '';

        if ($result->idempotent && $invoice->status === StatutoryInvoiceStatus::Cancelled) {
            return ['This statutory invoice was already cancelled.'.$correlationSuffix, 'success'];
        }

        $status = $operation?->status;

        if ($status === StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired) {
            return [
                'Cancellation stopped: manual Finance/CA review is required.'.$correlationSuffix.' '
                .StatutoryInvoiceCancellationAdminPresentation::FINANCE_CA_PROCEDURE,
                'danger',
            ];
        }

        if ($status === StatutoryInvoiceCancellationOperationStatus::IrnReconciliationPending) {
            return [
                'Cancellation request recorded but IRN outcome is unknown at the provider. '
                .'This does not mean the IRN is cancelled.'.$correlationSuffix
                .' Reconcile provider status before retrying; do not assume success.',
                'warning',
            ];
        }

        if (in_array($status, [
            StatutoryInvoiceCancellationOperationStatus::FailedRetryable,
            StatutoryInvoiceCancellationOperationStatus::IrnPending,
            StatutoryInvoiceCancellationOperationStatus::Requested,
        ], true) && $invoice->status === StatutoryInvoiceStatus::Issued) {
            return [
                'Cancellation request accepted and is in progress. IRN cancellation is not confirmed yet.'.$correlationSuffix,
                'warning',
            ];
        }

        if ($status === StatutoryInvoiceCancellationOperationStatus::IrnConfirmed
            || $status === StatutoryInvoiceCancellationOperationStatus::LocalPending) {
            return [
                'IRN cancellation confirmed at the provider; local finalization may still be in progress.'.$correlationSuffix,
                'warning',
            ];
        }

        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            return [
                'Statutory invoice cancelled locally. Verify IRN and credit-note outcomes on this page.'.$correlationSuffix,
                'success',
            ];
        }

        if (($result->creditNoteAction['status'] ?? null) === 'issued') {
            return [
                'Beyond-window adjustment recorded (credit note issued). Original invoice remains active.'.$correlationSuffix,
                'success',
            ];
        }

        return [
            'Cancellation workflow finished. Review operation status below.'.$correlationSuffix,
            'info',
        ];
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
