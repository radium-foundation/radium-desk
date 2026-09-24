<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\CustomerPaymentSource;
use App\Enums\PosHistoricalPaymentMethod;
use App\Enums\StatutoryInvoicePaymentBackfillOutcome;
use App\Models\InventoryCustomer;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoicePaymentReconciliation;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ServicePos\ServicePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StatutoryInvoicePaymentBackfillService
{
    public const EVENT_COMPLETED = 'statutory_invoice.payment_backfill.completed';

    public const EVENT_UNPAID_SUPERSEDED = 'statutory_invoice.payment_backfill.unpaid_superseded';

    public function __construct(
        private readonly StatutoryInvoicePaymentReconciliationService $reconciliation,
        private readonly ServicePaymentService $payments,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @param  array{
     *     outcome: string,
     *     amount?: float|int|string|null,
     *     payment_date?: string|null,
     *     payment_method?: string|null,
     *     bank_name?: string|null,
     *     bank_branch?: string|null,
     *     reference?: string|null,
     *     verification_remark?: string|null,
     *     confirm?: mixed,
     * }  $input
     */
    public function backfill(StatutoryInvoice $invoice, User $actor, array $input): StatutoryInvoicePaymentReconciliation
    {
        if (($input['confirm'] ?? null) !== '1' && ($input['confirm'] ?? null) !== 1 && ($input['confirm'] ?? null) !== true) {
            throw ValidationException::withMessages([
                'confirm' => 'Confirmation is required before submitting historical payment reconciliation.',
            ]);
        }

        $outcome = StatutoryInvoicePaymentBackfillOutcome::tryFrom((string) ($input['outcome'] ?? ''));
        if ($outcome === null || ! in_array($outcome, StatutoryInvoicePaymentBackfillOutcome::submissionCases(), true)) {
            throw ValidationException::withMessages([
                'outcome' => 'Select a valid payment status.',
            ]);
        }

        if (! $this->reconciliation->allowsBackfill($invoice)) {
            throw ValidationException::withMessages([
                'invoice' => 'This invoice is not eligible for historical payment backfill.',
            ]);
        }

        return match ($outcome) {
            StatutoryInvoicePaymentBackfillOutcome::Unpaid => $this->completeUnpaid($invoice, $actor, $input),
            StatutoryInvoicePaymentBackfillOutcome::VerifiedPayment => $this->recordVerifiedPayment($invoice, $actor, $input),
            default => throw ValidationException::withMessages([
                'outcome' => 'Select a valid payment status.',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function completeUnpaid(
        StatutoryInvoice $invoice,
        User $actor,
        array $input,
    ): StatutoryInvoicePaymentReconciliation {
        $idempotencyKey = $this->reconciliation->unpaidDecisionIdempotencyKey($invoice);
        $existing = StatutoryInvoicePaymentReconciliation::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();
        if ($existing !== null) {
            if ($existing->idempotency_key === $idempotencyKey && $existing->outcome === StatutoryInvoicePaymentBackfillOutcome::Unpaid) {
                return $existing->load(['recorder']);
            }

            if ($existing->isLocked() && $existing->outcome !== StatutoryInvoicePaymentBackfillOutcome::Unpaid) {
                throw ValidationException::withMessages([
                    'invoice' => 'Historical payment reconciliation was already completed for this invoice.',
                ]);
            }
        }

        if ($this->reconciliation->allocatedAmount($invoice) > 0) {
            throw ValidationException::withMessages([
                'invoice' => 'Verified payments already exist for this invoice. Record additional payments instead of marking it unpaid.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $actor, $input, $idempotencyKey, $existing): StatutoryInvoicePaymentReconciliation {
            $this->lockInvoice($invoice);

            if ($existing !== null && ! $existing->isLocked()) {
                $existing->delete();
            }

            if (StatutoryInvoicePaymentReconciliation::query()->where('statutory_invoice_id', $invoice->id)->exists()) {
                $record = StatutoryInvoicePaymentReconciliation::query()
                    ->where('statutory_invoice_id', $invoice->id)
                    ->firstOrFail();

                if ($record->idempotency_key === $idempotencyKey) {
                    return $record->load(['recorder']) ?? $record;
                }

                throw ValidationException::withMessages([
                    'invoice' => 'Historical payment reconciliation was already completed for this invoice.',
                ]);
            }

            $record = StatutoryInvoicePaymentReconciliation::query()->create([
                'statutory_invoice_id' => $invoice->id,
                'outcome' => StatutoryInvoicePaymentBackfillOutcome::Unpaid,
                'verified_amount' => 0,
                'verification_remark' => $this->nullableString($input['verification_remark'] ?? null),
                'source' => StatutoryInvoicePaymentReconciliation::SOURCE_HISTORICAL_POS_BACKFILL,
                'recorded_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'completed_at' => now(),
                'locked_at' => now(),
            ]);

            $this->auditCompleted($actor, $invoice, $record);

            return $record->fresh(['recorder']) ?? $record;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function recordVerifiedPayment(
        StatutoryInvoice $invoice,
        User $actor,
        array $input,
    ): StatutoryInvoicePaymentReconciliation {
        $invoice->loadMissing('inventorySale.customer');
        $customer = $invoice->inventorySale?->customer;
        if (! $customer instanceof InventoryCustomer) {
            throw ValidationException::withMessages([
                'invoice' => 'The linked POS sale customer is required before payment can be backfilled.',
            ]);
        }

        $method = PosHistoricalPaymentMethod::tryFrom((string) ($input['payment_method'] ?? ''));
        if ($method === null || ! $method->isAllowedForHistoricalBackfill()) {
            throw ValidationException::withMessages([
                'payment_method' => 'Select a valid historical payment method.',
            ]);
        }

        $amount = round((float) ($input['amount'] ?? 0), 2);
        $invoiceValue = round((float) $invoice->invoice_value, 2);
        $outstanding = $this->reconciliation->outstandingAmount($invoice);
        $paymentDate = trim((string) ($input['payment_date'] ?? ''));
        if ($paymentDate === '') {
            throw ValidationException::withMessages([
                'payment_date' => 'Payment date is required.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Verified payment amount must be greater than zero.',
            ]);
        }

        if ($amount - $outstanding > 0.001) {
            throw ValidationException::withMessages([
                'amount' => 'Verified payment cannot exceed the outstanding invoice balance.',
            ]);
        }

        $reference = $this->nullableString($input['reference'] ?? null);
        $bankName = $this->nullableString($input['bank_name'] ?? null) ?? $method->defaultBankName();
        $bankBranch = $this->nullableString($input['bank_branch'] ?? null);

        $this->assertMethodFields($method, $reference, $bankName, $bankBranch);

        $paymentIdempotencyKey = $this->reconciliation->paymentInstallmentIdempotencyKey(
            invoice: $invoice,
            paymentDate: $paymentDate,
            amount: $amount,
            method: $method,
            reference: $reference,
        );

        return DB::transaction(function () use (
            $invoice,
            $actor,
            $input,
            $customer,
            $method,
            $amount,
            $invoiceValue,
            $paymentDate,
            $reference,
            $bankName,
            $bankBranch,
            $paymentIdempotencyKey,
        ): StatutoryInvoicePaymentReconciliation {
            $this->lockInvoice($invoice);

            $existing = StatutoryInvoicePaymentReconciliation::query()
                ->where('statutory_invoice_id', $invoice->id)
                ->first();

            if ($existing !== null && $existing->isLocked()) {
                if ($existing->outcome === StatutoryInvoicePaymentBackfillOutcome::Unpaid) {
                    $this->auditUnpaidSuperseded($actor, $invoice, $existing);
                    $existing->delete();
                    $existing = null;
                } elseif ($existing->outcome === StatutoryInvoicePaymentBackfillOutcome::PartiallyPaid
                    && $this->reconciliation->outstandingAmount($invoice) > 0.001) {
                    // Continue recording installments against the open partial reconciliation row.
                } else {
                    throw ValidationException::withMessages([
                        'invoice' => 'Historical payment reconciliation was already completed for this invoice.',
                    ]);
                }
            }

            $payment = $this->payments->recordPayment(
                customer: $customer,
                amount: $amount,
                method: $method->label(),
                paymentDate: new \DateTimeImmutable($paymentDate),
                actor: $actor,
                reference: $reference,
                notes: $this->nullableString($input['verification_remark'] ?? null),
                idempotencyKey: 'payment:'.$paymentIdempotencyKey,
                bankName: $bankName,
                bankBranch: $bankBranch,
                source: CustomerPaymentSource::HistoricalPosBackfill,
                skipGenericBankTransferValidation: true,
            );

            $allocation = $this->payments->allocatePayment(
                payment: $payment,
                invoice: $invoice,
                amount: $amount,
                actor: $actor,
                idempotencyKey: 'alloc:'.$paymentIdempotencyKey,
            );

            $newAllocated = $this->reconciliation->allocatedAmount($invoice);
            $resolvedOutcome = abs($newAllocated - $invoiceValue) <= 0.001
                ? StatutoryInvoicePaymentBackfillOutcome::Paid
                : StatutoryInvoicePaymentBackfillOutcome::PartiallyPaid;
            $isComplete = $resolvedOutcome === StatutoryInvoicePaymentBackfillOutcome::Paid;

            $attributes = [
                'outcome' => $resolvedOutcome,
                'verified_amount' => $newAllocated,
                'payment_method' => $method->label(),
                'payment_date' => $paymentDate,
                'bank_name' => $bankName,
                'bank_branch' => $bankBranch,
                'reference' => $reference,
                'verification_remark' => $this->nullableString($input['verification_remark'] ?? null),
                'source' => StatutoryInvoicePaymentReconciliation::SOURCE_HISTORICAL_POS_BACKFILL,
                'customer_payment_id' => $payment->id,
                'payment_allocation_id' => $allocation->id,
                'recorded_by' => $actor->id,
                'idempotency_key' => $paymentIdempotencyKey,
                'completed_at' => now(),
                'locked_at' => now(),
            ];

            if ($existing !== null) {
                $existing->fill($attributes);
                $existing->save();
                $record = $existing->fresh(['payment', 'allocation', 'recorder']) ?? $existing;
            } else {
                $record = StatutoryInvoicePaymentReconciliation::query()->create(array_merge(
                    ['statutory_invoice_id' => $invoice->id],
                    $attributes,
                ));
                $record = $record->fresh(['payment', 'allocation', 'recorder']) ?? $record;
            }

            $this->auditCompleted($actor, $invoice, $record, $payment->id, $allocation->id);

            return $record;
        });
    }

    private function lockInvoice(StatutoryInvoice $invoice): void
    {
        StatutoryInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
    }

    private function assertMethodFields(
        PosHistoricalPaymentMethod $method,
        ?string $reference,
        ?string $bankName,
        ?string $bankBranch,
    ): void {
        $errors = [];

        if ($method->requiresReference() && $reference === null) {
            $errors['reference'] = 'Reference/UTR is required for the selected payment method.';
        }

        if ($method->requiresBankName() && $bankName === null) {
            $errors['bank_name'] = 'Bank name is required for the selected payment method.';
        }

        if ($method->requiresBankBranch() && $bankBranch === null) {
            $errors['bank_branch'] = 'Bank branch is required for the selected payment method.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function auditCompleted(
        User $actor,
        StatutoryInvoice $invoice,
        StatutoryInvoicePaymentReconciliation $record,
        ?int $paymentId = null,
        ?int $allocationId = null,
    ): void {
        $this->auditLogs->log(
            userId: $actor->id,
            event: self::EVENT_COMPLETED,
            auditable: $record,
            newValues: [
                'statutory_invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'inventory_sale_id' => $invoice->inventory_sale_id,
                'outcome' => $record->outcome->value,
                'verified_amount' => (float) $record->verified_amount,
                'payment_method' => $record->payment_method,
                'payment_date' => $record->payment_date?->toDateString(),
                'bank_name' => $record->bank_name,
                'bank_branch' => $record->bank_branch,
                'reference' => $record->reference,
                'verification_remark' => $record->verification_remark,
                'source' => $record->source,
                'customer_payment_id' => $paymentId,
                'payment_allocation_id' => $allocationId,
                'locked_at' => $record->locked_at?->toDateTimeString(),
            ],
        );
    }

    private function auditUnpaidSuperseded(
        User $actor,
        StatutoryInvoice $invoice,
        StatutoryInvoicePaymentReconciliation $record,
    ): void {
        $this->auditLogs->log(
            userId: $actor->id,
            event: self::EVENT_UNPAID_SUPERSEDED,
            auditable: $record,
            oldValues: [
                'statutory_invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'outcome' => $record->outcome->value,
                'verification_remark' => $record->verification_remark,
            ],
            newValues: [
                'reason' => 'Verified payment evidence superseded the prior unpaid reconciliation decision.',
            ],
        );
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
