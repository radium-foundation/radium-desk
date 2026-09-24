<?php

namespace App\Services\ServicePos;

use App\Enums\ServiceOrderPaymentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CustomerPayment;
use App\Models\InventoryCustomer;
use App\Models\PaymentAllocation;
use App\Models\ServiceOrder;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicePaymentService
{
    public const EVENT_PAYMENT_RECORDED = 'customer_payment.recorded';

    public const EVENT_PAYMENT_ALLOCATED = 'customer_payment.allocated';

    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    public function recordPayment(
        InventoryCustomer $customer,
        float $amount,
        string $method,
        \DateTimeInterface $paymentDate,
        User $actor,
        ?string $reference = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
        ?string $bankName = null,
        ?string $bankBranch = null,
    ): CustomerPayment {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount must be greater than zero.',
            ]);
        }

        $method = trim($method);
        if ($method === '') {
            throw ValidationException::withMessages([
                'method' => 'Select a payment method.',
            ]);
        }

        $reference = $this->nullableString($reference);
        $bankName = $this->nullableString($bankName);
        $bankBranch = $this->nullableString($bankBranch);

        $this->assertBankTransferFields($method, $bankName, $bankBranch, $reference);

        $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : null;

        if ($idempotencyKey !== null) {
            $existing = CustomerPayment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing->load('allocations');
            }
        }

        if ($reference !== null) {
            $duplicate = CustomerPayment::query()
                ->where('customer_id', $customer->id)
                ->where('reference', $reference)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'reference' => 'A payment with this reference already exists for this customer.',
                ]);
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
            $bankName,
            $bankBranch,
        ): CustomerPayment {
            $payment = CustomerPayment::query()->create([
                'payment_number' => 'CP-TMP-'.strtoupper(bin2hex(random_bytes(4))),
                'customer_id' => $customer->id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'bank_name' => $bankName,
                'bank_branch' => $bankBranch,
                'payment_date' => $paymentDate,
                'notes' => $notes,
                'recorded_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $payment->update([
                'payment_number' => sprintf('CP-%06d', $payment->id),
            ]);

            $payment = $payment->fresh(['allocations']) ?? $payment;

            $this->auditLogs->log(
                userId: $actor->id,
                event: self::EVENT_PAYMENT_RECORDED,
                auditable: $payment,
                newValues: [
                    'payment_number' => $payment->payment_number,
                    'customer_id' => $payment->customer_id,
                    'amount' => (float) $payment->amount,
                    'method' => $payment->method,
                    'reference' => $payment->reference,
                    'bank_name' => $payment->bank_name,
                    'bank_branch' => $payment->bank_branch,
                    'payment_date' => $payment->payment_date?->toDateString(),
                ],
            );

            return $payment;
        });
    }

    public function allocatePayment(
        CustomerPayment $payment,
        StatutoryInvoice $invoice,
        float $amount,
        User $actor,
        ?string $idempotencyKey = null,
    ): PaymentAllocation {
        $this->assertInvoiceSupportsPaymentRecording($invoice);

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

            $this->auditLogs->log(
                userId: $actor->id,
                event: self::EVENT_PAYMENT_ALLOCATED,
                auditable: $allocation,
                newValues: [
                    'customer_payment_id' => $payment->id,
                    'statutory_invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => (float) $allocation->amount,
                    'payment_method' => $payment->method,
                    'payment_reference' => $payment->reference,
                    'bank_name' => $payment->bank_name,
                    'bank_branch' => $payment->bank_branch,
                ],
            );

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

    public function assertInvoiceSupportsPaymentRecording(StatutoryInvoice $invoice): void
    {
        if (! in_array($invoice->channel, [
            StatutoryInvoiceChannel::DeskService,
            StatutoryInvoiceChannel::DeskPos,
        ], true)) {
            throw ValidationException::withMessages([
                'invoice' => 'Only Desk service or POS statutory invoices can receive customer payment allocations.',
            ]);
        }
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

    private function assertBankTransferFields(
        string $method,
        ?string $bankName,
        ?string $bankBranch,
        ?string $reference,
    ): void {
        if (! $this->isBankTransferMethod($method)) {
            return;
        }

        $errors = [];
        if ($bankName === null) {
            $errors['bank_name'] = 'Bank name is required for bank transfer payments.';
        }
        if ($bankBranch === null) {
            $errors['bank_branch'] = 'Bank branch is required for bank transfer payments.';
        }
        if ($reference === null) {
            $errors['reference'] = 'Reference/UTR is required for bank transfer payments.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function isBankTransferMethod(string $method): bool
    {
        $normalized = strtolower(trim($method));

        return in_array($normalized, [
            'bank transfer',
            'bank_transfer',
            'neft',
            'rtgs',
            'imps',
        ], true);
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
