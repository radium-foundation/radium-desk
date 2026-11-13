<?php

namespace App\Reports\CaMonthly;

use App\Enums\RefundStatutoryAdjustmentStatus;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Support\Finance\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Period selection for refund-driven credit notes and paired original invoices.
 *
 * Credit notes stay on their real {@see StatutoryInvoice::issued_at}.
 * Cancellation eligibility uses refund execution or statutory cancellation completion.
 */
final class CaMonthlyReportCancellationReportingScope
{
    public static function eligibleInitiationOnOrAfter(): string
    {
        $configured = config('ca_monthly_report.refund_cancellation_eligible_on_or_after', '2026-09-07');

        return is_string($configured) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $configured) === 1
            ? $configured
            : '2026-09-07';
    }

    public static function applyPeriodFilter(Builder $query, ReportPeriod $period): Builder
    {
        $dateColumn = CaMonthlyReportDefinition::AUTHORITATIVE_DATE_COLUMN;
        $creditNoteType = StatutoryInvoiceDocumentType::CreditNote->value;
        $issued = StatutoryInvoiceStatus::Issued->value;
        $cancelled = StatutoryInvoiceStatus::Cancelled->value;

        $eligibleCreditNoteIds = self::eligibleCreditNoteIdsQuery($period);
        $pairedOriginalIds = StatutoryInvoice::query()
            ->select('original_statutory_invoice_id')
            ->whereIn('id', self::eligibleCreditNoteIdsQuery($period))
            ->whereNotNull('original_statutory_invoice_id');

        return $query->where(function (Builder $outer) use (
            $period,
            $dateColumn,
            $creditNoteType,
            $issued,
            $cancelled,
            $eligibleCreditNoteIds,
            $pairedOriginalIds,
        ): void {
            $outer->where(function (Builder $standard) use ($period, $dateColumn, $creditNoteType, $issued, $cancelled): void {
                $standard
                    ->where('document_type', '!=', $creditNoteType)
                    ->whereIn('status', [$issued, $cancelled]);
                $period->apply($standard, $dateColumn);
            })
                ->orWhereIn('statutory_invoices.id', $eligibleCreditNoteIds)
                ->orWhereIn('statutory_invoices.id', $pairedOriginalIds);
        });
    }

    /**
     * @return Builder<StatutoryInvoice>
     */
    private static function eligibleCreditNoteIdsQuery(ReportPeriod $period): Builder
    {
        $dateColumn = CaMonthlyReportDefinition::AUTHORITATIVE_DATE_COLUMN;
        $minimumInitiation = self::eligibleInitiationOnOrAfter();
        $creditNoteType = StatutoryInvoiceDocumentType::CreditNote->value;

        $query = StatutoryInvoice::query()
            ->select('id')
            ->where('document_type', $creditNoteType);
        $period->apply($query, $dateColumn);
        self::whereCancellationInitiatedOnOrAfter($query, $minimumInitiation);

        return $query;
    }

    private static function whereCancellationInitiatedOnOrAfter(Builder $creditNoteQuery, string $minimumInitiationDate): void
    {
        $creditNoteQuery->where(function (Builder $eligible) use ($minimumInitiationDate): void {
            $eligible->whereExists(function ($refundPath) use ($minimumInitiationDate): void {
                $refundPath->select(DB::raw('1'))
                    ->from('refund_statutory_adjustments as rsa')
                    ->join('refund_requests as rr', 'rr.id', '=', 'rsa.refund_request_id')
                    ->whereColumn('rsa.statutory_invoice_id', 'statutory_invoices.original_statutory_invoice_id')
                    ->where('rsa.status', RefundStatutoryAdjustmentStatus::Succeeded->value)
                    ->whereNotNull('rr.executed_at')
                    ->whereDate('rr.executed_at', '>=', $minimumInitiationDate);
            })->orWhereExists(function ($cancellationPath) use ($minimumInitiationDate): void {
                $cancellationPath->select(DB::raw('1'))
                    ->from('statutory_invoice_cancellations as sic')
                    ->whereColumn('sic.statutory_invoice_id', 'statutory_invoices.original_statutory_invoice_id')
                    ->whereNotNull('sic.completed_at')
                    ->whereDate('sic.completed_at', '>=', $minimumInitiationDate)
                    ->whereNotExists(function ($refundOverrides): void {
                        $refundOverrides->select(DB::raw('1'))
                            ->from('refund_statutory_adjustments as rsa2')
                            ->whereColumn('rsa2.statutory_invoice_id', 'statutory_invoices.original_statutory_invoice_id')
                            ->where('rsa2.status', RefundStatutoryAdjustmentStatus::Succeeded->value);
                    });
            });
        });
    }
}
