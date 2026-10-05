<?php

namespace App\Reports\CaMonthly;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Models\RefundRequest;
use App\Models\StatutoryInvoice;
use App\Services\Refunds\OrderStatutoryInvoiceResolver;
use App\Support\Finance\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds exception-only refund review rows for CA/accounting statutory follow-up.
 *
 * Scope: terminal-success refunds completed in the reporting period that require
 * CA/Finance review. This is not a full refund register.
 */
final class CaMonthlyReportRefundReviewExportBuilder
{
    public function __construct(
        private readonly OrderStatutoryInvoiceResolver $invoiceResolver,
        private readonly CaMonthlyReportRefundReviewClassifier $classifier,
    ) {}

    /**
     * @return list<CaMonthlyReportRefundReviewExportRow>
     */
    public function buildForRequest(Request $request): array
    {
        $period = ReportPeriod::fromRequest($request);
        $rows = [];

        $this->refundQuery($period)
            ->with(['order'])
            ->orderBy('id')
            ->chunkById(50, function (Collection $refunds) use (&$rows): void {
                $creditNotesByInvoice = $this->creditNotesForRefunds($refunds);

                foreach ($refunds as $refund) {
                    $row = $this->buildOne($refund, $creditNotesByInvoice);
                    if ($row !== null) {
                        $rows[] = $row;
                    }
                }
            });

        return $rows;
    }

    private function buildOne(RefundRequest $refund, array $creditNotesByInvoice): ?CaMonthlyReportRefundReviewExportRow
    {
        $order = $refund->order;
        if ($order === null) {
            return null;
        }

        $resolution = $this->invoiceResolver->resolveForOrder($order);
        $invoice = $resolution->invoice;
        if ($invoice === null) {
            return null;
        }

        $invoice->loadMissing(['eInvoiceRecord']);
        $refundCompletedAt = $this->refundCompletedAt($refund);
        $invoiceIssuedAt = $this->asCarbon($invoice->issued_at);
        $irnAckDate = $this->asCarbon($invoice->eInvoiceRecord?->ack_date);
        $creditNotes = $creditNotesByInvoice[$invoice->id] ?? collect();
        $cumulativeRefund = $this->cumulativeRefundAmount((int) $refund->order_id);

        $reviewStatus = $this->classifier->reviewStatus(
            refund: $refund,
            invoice: $invoice,
            cumulativeRefundAmount: $cumulativeRefund,
            hasCreditNote: $creditNotes->isNotEmpty(),
            refundCompletedAt: $refundCompletedAt,
            invoiceIssuedAt: $invoiceIssuedAt,
            irnAckDate: $irnAckDate,
        );

        if ($reviewStatus === null) {
            return null;
        }

        $creditNoteSummary = $this->summarizeCreditNotes($creditNotes);
        $irnAgeHours = $this->classifier->irnAgeHoursAtRefund($refundCompletedAt, $irnAckDate, $invoiceIssuedAt);

        return new CaMonthlyReportRefundReviewExportRow([
            (string) $refund->id,
            (string) $refund->reference_no,
            (string) ($order->order_id ?? ''),
            (string) $invoice->invoice_number,
            $refundCompletedAt?->timezone((string) config('app.timezone'))->format('Y-m-d H:i:s') ?? '',
            $this->refundMethodLabel($refund->approved_refund_method),
            $this->money((float) ($refund->refund_amount ?? $refund->amount ?? 0)),
            $this->money((float) $invoice->invoice_value),
            $this->money($cumulativeRefund),
            CaMonthlyReportStatusDisplay::forInvoice($invoice),
            CaMonthlyReportCustomerTypeDisplay::forInvoice($invoice),
            $irnAckDate?->timezone((string) config('app.timezone'))->format('Y-m-d H:i:s') ?? '',
            $irnAgeHours !== null ? number_format($irnAgeHours, 2, '.', '') : '',
            $creditNoteSummary['number'],
            $creditNoteSummary['status'],
            $creditNoteSummary['amount'],
            $reviewStatus,
        ]);
    }

    private function refundQuery(ReportPeriod $period): Builder
    {
        return RefundRequest::query()
            ->whereNull('deleted_at')
            ->whereIn('status', [
                RefundStatus::Completed->value,
                RefundStatus::Closed->value,
                RefundStatus::Approved->value,
            ])
            ->where(function (Builder $query) use ($period): void {
                $query->where(function (Builder $executed) use ($period): void {
                    $period->apply($executed, 'executed_at');
                })->orWhere(function (Builder $closed) use ($period): void {
                    $period->apply($closed, 'closed_at');
                });
            });
    }

    /**
     * @param  Collection<int, RefundRequest>  $refunds
     * @return array<int, Collection<int, StatutoryInvoice>>
     */
    private function creditNotesForRefunds(Collection $refunds): array
    {
        $invoiceIds = [];
        foreach ($refunds as $refund) {
            $order = $refund->order;
            if ($order === null) {
                continue;
            }

            $invoice = $this->invoiceResolver->resolveForOrder($order)->invoice;
            if ($invoice !== null) {
                $invoiceIds[] = $invoice->id;
            }
        }

        if ($invoiceIds === []) {
            return [];
        }

        return StatutoryInvoice::query()
            ->whereIn('original_statutory_invoice_id', array_values(array_unique($invoiceIds)))
            ->where('document_type', StatutoryInvoiceDocumentType::CreditNote)
            ->get()
            ->groupBy('original_statutory_invoice_id')
            ->all();
    }

    private function cumulativeRefundAmount(int $orderId): float
    {
        return (float) RefundRequest::query()
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->whereIn('status', [
                RefundStatus::Completed->value,
                RefundStatus::Closed->value,
                RefundStatus::Approved->value,
            ])
            ->get(['refund_amount', 'amount'])
            ->sum(fn (RefundRequest $refund): float => (float) ($refund->refund_amount ?? $refund->amount ?? 0));
    }

    /**
     * @param  Collection<int, StatutoryInvoice>  $creditNotes
     * @return array{number: string, status: string, amount: string}
     */
    private function summarizeCreditNotes(Collection $creditNotes): array
    {
        if ($creditNotes->isEmpty()) {
            return ['number' => '', 'status' => '', 'amount' => ''];
        }

        return [
            'number' => $creditNotes->pluck('invoice_number')->filter()->implode(', '),
            'status' => $creditNotes
                ->map(fn (StatutoryInvoice $creditNote): string => CaMonthlyReportStatusDisplay::forInvoice($creditNote))
                ->unique()
                ->implode(', '),
            'amount' => $this->money((float) $creditNotes->sum(fn (StatutoryInvoice $creditNote): float => (float) $creditNote->invoice_value)),
        ];
    }

    private function refundCompletedAt(RefundRequest $refund): ?Carbon
    {
        return $this->asCarbon($refund->executed_at ?? $refund->closed_at);
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    private function refundMethodLabel(?ApprovedRefundMethod $method): string
    {
        return match ($method) {
            ApprovedRefundMethod::Wallet => 'Wallet',
            ApprovedRefundMethod::Cashfree => 'OPM (Cashfree)',
            ApprovedRefundMethod::BankTransfer => 'Bank Transfer',
            ApprovedRefundMethod::Upi => 'UPI',
            ApprovedRefundMethod::Other => 'Other',
            default => '',
        };
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
