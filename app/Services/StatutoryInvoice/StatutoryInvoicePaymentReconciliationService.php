<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\PosHistoricalPaymentMethod;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoicePaymentBackfillOutcome;
use App\Enums\StatutoryInvoicePaymentReconciliationStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\PaymentAllocation;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoicePaymentReconciliation;
use Illuminate\Support\Carbon;

final class StatutoryInvoicePaymentReconciliationService
{
    public const HISTORICAL_PERIOD_START = '2026-09-01 00:00:00';

    public function historicalPeriodStart(): Carbon
    {
        return Carbon::parse(self::HISTORICAL_PERIOD_START, (string) config('app.timezone'))->startOfDay();
    }

    public function isHistoricalPosInvoice(StatutoryInvoice $invoice): bool
    {
        if ($invoice->channel !== StatutoryInvoiceChannel::DeskPos) {
            return false;
        }

        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::InventorySale->value) {
            return false;
        }

        if ($invoice->inventory_sale_id === null) {
            return false;
        }

        if ($invoice->issued_at === null) {
            return false;
        }

        return $invoice->issued_at->greaterThanOrEqualTo($this->historicalPeriodStart());
    }

    public function allocatedAmount(StatutoryInvoice $invoice): float
    {
        return round((float) PaymentAllocation::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->sum('amount'), 2);
    }

    public function outstandingAmount(StatutoryInvoice $invoice): float
    {
        return max(0, round((float) $invoice->invoice_value, 2) - $this->allocatedAmount($invoice));
    }

    public function reconciliationRecord(StatutoryInvoice $invoice): ?StatutoryInvoicePaymentReconciliation
    {
        return StatutoryInvoicePaymentReconciliation::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();
    }

    public function reconciliationStatus(StatutoryInvoice $invoice): ?StatutoryInvoicePaymentReconciliationStatus
    {
        if (! $this->isHistoricalPosInvoice($invoice)) {
            return null;
        }

        $record = $this->reconciliationRecord($invoice);
        if ($record !== null && $record->isLocked()) {
            if ($record->outcome === StatutoryInvoicePaymentBackfillOutcome::PartiallyPaid
                && $this->outstandingAmount($invoice) > 0.001) {
                return StatutoryInvoicePaymentReconciliationStatus::Required;
            }

            return StatutoryInvoicePaymentReconciliationStatus::Completed;
        }

        return StatutoryInvoicePaymentReconciliationStatus::Required;
    }

    public function requiresReconciliation(StatutoryInvoice $invoice): bool
    {
        return $this->reconciliationStatus($invoice) === StatutoryInvoicePaymentReconciliationStatus::Required;
    }

    public function allowsBackfill(StatutoryInvoice $invoice): bool
    {
        if ($invoice->status !== StatutoryInvoiceStatus::Issued) {
            return false;
        }

        if (! $this->isHistoricalPosInvoice($invoice)) {
            return false;
        }

        $record = $this->reconciliationRecord($invoice);
        if ($record !== null && $record->isLocked()) {
            if ($record->outcome === StatutoryInvoicePaymentBackfillOutcome::Unpaid) {
                return true;
            }

            if ($record->outcome === StatutoryInvoicePaymentBackfillOutcome::PartiallyPaid
                && $this->outstandingAmount($invoice) > 0.001) {
                return true;
            }

            return false;
        }

        return $this->outstandingAmount($invoice) > 0.001 || $record === null;
    }

    public function allowsAdditionalPaymentRecording(StatutoryInvoice $invoice): bool
    {
        $record = $this->reconciliationRecord($invoice);
        if ($record === null) {
            return true;
        }

        if (! $record->isLocked()) {
            return true;
        }

        return $record->outcome !== StatutoryInvoicePaymentBackfillOutcome::Unpaid;
    }

    public function unpaidDecisionIdempotencyKey(StatutoryInvoice $invoice): string
    {
        return 'historical-pos-backfill:'.$invoice->id.':unpaid';
    }

    public function paymentInstallmentIdempotencyKey(
        StatutoryInvoice $invoice,
        string $paymentDate,
        float $amount,
        PosHistoricalPaymentMethod|string $method,
        ?string $reference,
    ): string {
        $methodValue = $method instanceof PosHistoricalPaymentMethod
            ? $method->value
            : strtolower(trim($method));

        $referencePart = $reference !== null && trim($reference) !== ''
            ? trim($reference)
            : 'cash:'.$methodValue;

        return sprintf(
            'historical-pos-backfill:%d:payment:%s:%s:%s',
            $invoice->id,
            $referencePart,
            number_format($amount, 2, '.', ''),
            $paymentDate,
        );
    }
}
