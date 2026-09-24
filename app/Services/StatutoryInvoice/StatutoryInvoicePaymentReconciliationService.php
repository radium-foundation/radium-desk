<?php

namespace App\Services\StatutoryInvoice;

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

        if ($this->reconciliationRecord($invoice) !== null) {
            return StatutoryInvoicePaymentReconciliationStatus::Completed;
        }

        if ($this->allocatedAmount($invoice) > 0) {
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

        if ($this->reconciliationRecord($invoice) !== null) {
            return false;
        }

        return $this->allocatedAmount($invoice) <= 0;
    }

    public function allowsAdditionalPaymentRecording(StatutoryInvoice $invoice): bool
    {
        $record = $this->reconciliationRecord($invoice);
        if ($record === null) {
            return true;
        }

        return $record->outcome !== StatutoryInvoicePaymentBackfillOutcome::Unpaid;
    }

    public function idempotencyKey(StatutoryInvoice $invoice): string
    {
        return 'historical-pos-backfill:'.$invoice->id;
    }
}
