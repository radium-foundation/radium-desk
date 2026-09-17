<?php

namespace App\Services\Purchasing;

use App\Enums\PurchasePaymentStatus;
use App\Models\PurchasePayment;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchasePaymentService
{
    public function __construct(
        private readonly PurchasingAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(SupplierInvoice $invoice, array $data, User $actor): PurchasePayment
    {
        return DB::transaction(function () use ($invoice, $data, $actor): PurchasePayment {
            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount must be greater than zero.',
                ]);
            }

            $existingPaid = (float) $invoice->payments()->sum('amount');
            if ($existingPaid + $amount > (float) $invoice->invoice_amount + 0.009) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment would exceed the supplier invoice amount.',
                ]);
            }

            $payment = PurchasePayment::query()->create([
                'supplier_invoice_id' => $invoice->id,
                'vendor_id' => $invoice->vendor_id,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'amount' => $amount,
                'payment_method' => trim((string) $data['payment_method']),
                'transaction_reference' => filled($data['transaction_reference'] ?? null) ? trim((string) $data['transaction_reference']) : null,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'recorded_by_user_id' => $actor->id,
            ]);

            $this->refreshPaymentStatus($invoice);
            $this->audit->log($actor, 'purchase_payment.recorded', $payment, null, $payment->toArray());

            return $payment;
        });
    }

    public function refreshPaymentStatus(SupplierInvoice $invoice): SupplierInvoice
    {
        $paid = (string) $invoice->payments()->sum('amount');
        $status = PurchasePaymentStatus::fromAmounts((string) $invoice->invoice_amount, $paid);

        $invoice->update([
            'amount_paid' => $paid,
            'payment_status' => $status,
        ]);

        return $invoice->fresh();
    }
}
