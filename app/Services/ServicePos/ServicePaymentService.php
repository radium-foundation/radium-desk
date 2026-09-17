<?php

namespace App\Services\ServicePos;

use App\Enums\ServiceOrderPaymentStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CustomerPayment;
use App\Models\InventoryCustomer;
use App\Models\PaymentAllocation;
use App\Models\ServiceOrder;
use App\Models\StatutoryInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicePaymentService
{
    public function recordPayment(
        InventoryCustomer $customer,
        float $amount,
        string $method,
        \DateTimeInterface $paymentDate,
        User $actor,
        ?string $reference = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): CustomerPayment {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount must be greater than zero.',
            ]);
        }

        $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : null;

        if ($idempotencyKey !== null) {
            $existing = CustomerPayment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing->load('allocations');
            }
        }

        return DB::transaction(function () use (
            $customer,
            $amount,
            $method,
            $paymentDate,
            $actor,
            $reference,
            $notes,
            $idempotencyKey,
        ): CustomerPayment {
            $payment = CustomerPayment::query()->create([
                'payment_number' => 'CP-TMP-'.strtoupper(bin2hex(random_bytes(4))),
                'customer_id' => $customer->id,
                'amount' => $amount,
                'method' => trim($method),
                'reference' => $reference,
                'payment_date' => $paymentDate,
                'notes' => $notes,
                'recorded_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $payment->update([
                'payment_number' => sprintf('CP-%06d', $payment->id),
            ]);

            return $payment->fresh(['allocations']) ?? $payment;
        });
    }

    public function allocatePayment(
        CustomerPayment $payment,
        StatutoryInvoice $invoice,
        float $amount,
        User $actor,
        ?string $idempotencyKey = null,
    ): PaymentAllocation {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Allocation amount must be greater than zero.',
            ]);
        }

        $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : null;

        if ($idempotencyKey !== null) {
            $existing = PaymentAllocation::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($payment, $invoice, $amount, $actor, $idempotencyKey): PaymentAllocation {
            $payment = CustomerPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $invoice = StatutoryInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'invoice' => 'Cancelled invoices cannot receive payment allocations.',
                ]);
            }

            $paymentAllocated = $this->allocatedForPayment($payment);
            $paymentRemaining = round((float) $payment->amount - $paymentAllocated, 2);
            if ($amount > $paymentRemaining) {
                throw ValidationException::withMessages([
                    'amount' => 'Allocation exceeds the unallocated payment balance.',
                ]);
            }

            $outstanding = $this->outstandingForInvoice($invoice);
            if ($amount > $outstanding) {
                throw ValidationException::withMessages([
                    'amount' => 'Allocation exceeds the invoice outstanding balance.',
                ]);
            }

            $allocation = PaymentAllocation::query()->create([
                'customer_payment_id' => $payment->id,
                'statutory_invoice_id' => $invoice->id,
                'amount' => $amount,
                'idempotency_key' => $idempotencyKey,
                'allocated_by' => $actor->id,
                'allocated_at' => now(),
            ]);

            $this->refreshLinkedServiceOrderPaymentStatus($invoice);

            return $allocation;
        });
    }

    public function outstandingForInvoice(StatutoryInvoice $invoice): float
    {
        $invoiceValue = round((float) $invoice->invoice_value, 2);
        $allocated = round((float) PaymentAllocation::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->sum('amount'), 2);

        return max(0, round($invoiceValue - $allocated, 2));
    }

    public function allocatedForPayment(CustomerPayment $payment): float
    {
        return round((float) PaymentAllocation::query()
            ->where('customer_payment_id', $payment->id)
            ->sum('amount'), 2);
    }

    public function paymentStatusForInvoice(StatutoryInvoice $invoice): ServiceOrderPaymentStatus
    {
        $outstanding = $this->outstandingForInvoice($invoice);
        $invoiceValue = round((float) $invoice->invoice_value, 2);

        if ($outstanding <= 0 && $invoiceValue > 0) {
            return ServiceOrderPaymentStatus::Paid;
        }

        $allocated = round($invoiceValue - $outstanding, 2);
        if ($allocated > 0 && $outstanding > 0) {
            return ServiceOrderPaymentStatus::Partial;
        }

        return ServiceOrderPaymentStatus::Unpaid;
    }

    private function refreshLinkedServiceOrderPaymentStatus(StatutoryInvoice $invoice): void
    {
        $order = ServiceOrder::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();

        if ($order === null) {
            return;
        }

        $order->update([
            'payment_status' => $this->paymentStatusForInvoice($invoice),
        ]);
    }
}
