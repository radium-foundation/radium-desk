<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoicePaymentStatus;
use App\Models\PaymentAllocation;
use App\Models\StatutoryInvoice;
use App\Services\ServicePos\ServicePaymentService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoicePaymentSummary;

final class StatutoryInvoicePaymentReadService
{
    public function __construct(
        private readonly ServicePaymentService $payments,
    ) {}

    public function usesAllocationBackedPaymentEvidence(StatutoryInvoice $invoice): bool
    {
        return in_array($invoice->channel, [
            StatutoryInvoiceChannel::DeskPos,
            StatutoryInvoiceChannel::DeskService,
        ], true);
    }

    public function summary(StatutoryInvoice $invoice): StatutoryInvoicePaymentSummary
    {
        $invoice->loadMissing('inventorySale');

        $invoiceValue = round((float) $invoice->invoice_value, 2);
        $amountReceived = round((float) PaymentAllocation::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->sum('amount'), 2);
        $amountOutstanding = max(0, round($invoiceValue - $amountReceived, 2));

        $paymentRows = PaymentAllocation::query()
            ->with(['payment.recorder'])
            ->where('statutory_invoice_id', $invoice->id)
            ->orderByDesc('allocated_at')
            ->orderByDesc('id')
            ->get();

        $payments = $paymentRows->map(function (PaymentAllocation $allocation): array {
            $payment = $allocation->payment;

            return [
                'allocation_id' => $allocation->id,
                'payment_id' => $payment?->id,
                'payment_number' => $payment?->payment_number,
                'amount' => round((float) $allocation->amount, 2),
                'method' => $payment?->method,
                'reference' => $payment?->reference,
                'bank_name' => $payment?->bank_name,
                'bank_branch' => $payment?->bank_branch,
                'payment_date' => $payment?->payment_date?->toDateString(),
                'recorded_by' => $payment?->recorder?->name,
                'allocated_at' => $allocation->allocated_at?->toDateTimeString(),
            ];
        })->values()->all();

        $latestPayment = $paymentRows->first()?->payment;

        if (! $this->usesAllocationBackedPaymentEvidence($invoice)) {
            return new StatutoryInvoicePaymentSummary(
                allocationBacked: false,
                status: $this->legacyChannelStatus($invoice, $invoiceValue, $amountReceived),
                invoiceValue: $invoiceValue,
                amountReceived: $amountReceived,
                amountOutstanding: $amountOutstanding,
                posTenderMethod: $this->nullableString($invoice->payment_method),
                posTenderReference: $this->nullableString($invoice->payment_reference),
                latestPaymentMethod: $this->nullableString($invoice->payment_method),
                latestPaymentDate: $invoice->issued_at?->toDateString(),
                latestReference: $this->nullableString($invoice->payment_reference),
                inventorySaleId: $invoice->inventory_sale_id,
                inventorySaleReference: $invoice->inventorySale?->sale_no,
                payments: $payments,
            );
        }

        return new StatutoryInvoicePaymentSummary(
            allocationBacked: true,
            status: $this->allocationBackedStatus($invoiceValue, $amountReceived),
            invoiceValue: $invoiceValue,
            amountReceived: $amountReceived,
            amountOutstanding: $amountOutstanding,
            posTenderMethod: $this->nullableString($invoice->payment_method),
            posTenderReference: $this->nullableString($invoice->payment_reference),
            latestPaymentMethod: $this->nullableString($latestPayment?->method),
            latestPaymentDate: $latestPayment?->payment_date?->toDateString(),
            latestBankName: $this->nullableString($latestPayment?->bank_name),
            latestBankBranch: $this->nullableString($latestPayment?->bank_branch),
            latestReference: $this->nullableString($latestPayment?->reference),
            inventorySaleId: $invoice->inventory_sale_id,
            inventorySaleReference: $invoice->inventorySale?->sale_no,
            payments: $payments,
        );
    }

    private function allocationBackedStatus(float $invoiceValue, float $amountReceived): StatutoryInvoicePaymentStatus
    {
        if ($invoiceValue <= 0) {
            return StatutoryInvoicePaymentStatus::Unpaid;
        }

        if ($amountReceived <= 0) {
            return StatutoryInvoicePaymentStatus::Unpaid;
        }

        if ($amountReceived + 0.001 >= $invoiceValue) {
            return StatutoryInvoicePaymentStatus::Paid;
        }

        return StatutoryInvoicePaymentStatus::PartiallyPaid;
    }

    private function legacyChannelStatus(
        StatutoryInvoice $invoice,
        float $invoiceValue,
        float $amountReceived,
    ): StatutoryInvoicePaymentStatus {
        if ($amountReceived > 0) {
            return $this->allocationBackedStatus($invoiceValue, $amountReceived);
        }

        if ($this->nullableString($invoice->payment_method) !== null) {
            return StatutoryInvoicePaymentStatus::Paid;
        }

        return StatutoryInvoicePaymentStatus::Unpaid;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
