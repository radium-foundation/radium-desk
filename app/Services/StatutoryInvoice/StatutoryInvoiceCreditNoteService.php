<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StatutoryInvoiceCreditNoteService
{
    public const IDEMPOTENCY_PREFIX = 'statutory-credit-note:';

    public function __construct(
        private readonly StatutoryInvoiceNumberingService $numbering,
        private readonly StatutorySellerIdentity $sellers,
        private readonly StatutoryInvoiceService $invoices,
    ) {}

    /**
     * @return array{status: string, message: string, credit_note_id?: int, credit_note_number?: string, idempotent?: bool}
     */
    public function issueForCancellation(
        StatutoryInvoice $original,
        User $actor,
        string $reason,
        string $idempotencyKey,
    ): array {
        if ($original->status !== StatutoryInvoiceStatus::Issued) {
            throw ValidationException::withMessages([
                'invoice' => 'Credit notes can only be issued against an active issued tax invoice.',
            ]);
        }

        if ($original->document_type !== StatutoryInvoiceDocumentType::TaxInvoice) {
            throw ValidationException::withMessages([
                'invoice' => 'Credit notes can only be issued against tax invoices.',
            ]);
        }

        $existing = $this->findExistingCreditNote($original);
        if ($existing !== null) {
            return [
                'status' => 'issued',
                'message' => 'Credit note already exists for this statutory invoice.',
                'credit_note_id' => $existing->id,
                'credit_note_number' => (string) $existing->invoice_number,
                'idempotent' => true,
            ];
        }

        $creditNote = DB::transaction(function () use ($original, $actor, $reason, $idempotencyKey): StatutoryInvoice {
            $lockedOriginal = StatutoryInvoice::query()
                ->whereKey($original->id)
                ->lockForUpdate()
                ->firstOrFail();

            $again = $this->findExistingCreditNote($lockedOriginal);
            if ($again !== null) {
                return $again;
            }

            if ($lockedOriginal->status !== StatutoryInvoiceStatus::Issued) {
                throw ValidationException::withMessages([
                    'invoice' => 'Credit notes can only be issued against an active issued tax invoice.',
                ]);
            }

            $lockedOriginal->loadMissing('items');
            $location = $this->sellers->locationForInvoice($lockedOriginal);
            if ($location === null) {
                throw ValidationException::withMessages([
                    'credit_note' => 'Unable to resolve statutory numbering location for the credit note.',
                ]);
            }

            $allocation = $this->numbering->allocate(
                idempotencyKey: $idempotencyKey,
                actor: $actor,
                location: $location,
            );

            $creditNote = StatutoryInvoice::query()->create([
                'invoice_number' => $allocation->allocated_number,
                'sequence_allocation_id' => $allocation->id,
                'document_type' => StatutoryInvoiceDocumentType::CreditNote,
                'status' => StatutoryInvoiceStatus::Issued,
                'channel' => $lockedOriginal->channel,
                'source_type' => StatutoryInvoiceSourceType::CancellationAdjustment->value,
                'source_id' => (string) $lockedOriginal->id,
                'source_order_id' => $lockedOriginal->source_order_id,
                'idempotency_key' => $idempotencyKey,
                'original_statutory_invoice_id' => $lockedOriginal->id,
                'inventory_sale_id' => null,
                'support_order_id' => $lockedOriginal->support_order_id,
                'branch_id' => $lockedOriginal->branch_id,
                'seller_gstin' => $lockedOriginal->seller_gstin,
                'seller_name' => $lockedOriginal->seller_name,
                'buyer_name' => $lockedOriginal->buyer_name,
                'buyer_phone' => $lockedOriginal->buyer_phone,
                'buyer_gstin' => $lockedOriginal->buyer_gstin,
                'billing_address' => $lockedOriginal->billing_address,
                'billing_address_structured' => $lockedOriginal->billing_address_structured,
                'place_of_supply_state' => $lockedOriginal->place_of_supply_state,
                'place_of_supply_state_code' => $lockedOriginal->place_of_supply_state_code,
                'place_of_supply_source' => $lockedOriginal->place_of_supply_source,
                'taxable_value' => $lockedOriginal->taxable_value,
                'shipping_amount' => $lockedOriginal->shipping_amount,
                'discount' => $lockedOriginal->discount,
                'tax_total' => $lockedOriginal->tax_total,
                'cgst' => $lockedOriginal->cgst,
                'sgst' => $lockedOriginal->sgst,
                'igst' => $lockedOriginal->igst,
                'rounding' => $lockedOriginal->rounding,
                'invoice_value' => $lockedOriginal->invoice_value,
                'payment_method' => $lockedOriginal->payment_method,
                'payment_reference' => $lockedOriginal->payment_reference,
                'finance_journal_id' => null,
                'issued_by' => $actor->id,
                'issued_at' => now(),
                'cancel_reason' => trim($reason) !== '' ? trim($reason) : null,
            ]);

            foreach ($lockedOriginal->items as $item) {
                StatutoryInvoiceItem::query()->create([
                    'invoice_id' => $creditNote->id,
                    'line_no' => $item->line_no,
                    'sku' => $item->sku,
                    'description' => $item->description,
                    'hsn_sac' => $item->hsn_sac,
                    'uqc' => $item->uqc,
                    'qty' => $item->qty,
                    'unit_price' => $item->unit_price,
                    'discount' => $item->discount,
                    'gst_percentage' => $item->gst_percentage,
                    'taxable_value' => $item->taxable_value,
                    'tax_total' => $item->tax_total,
                    'cgst' => $item->cgst,
                    'sgst' => $item->sgst,
                    'igst' => $item->igst,
                    'line_total' => $item->line_total,
                ]);
            }

            if ($allocation->invoice_id === null) {
                $allocation->update(['invoice_id' => $creditNote->id]);
            }

            $this->queueCreditNoteIrnIfEligible($creditNote->fresh(['items']) ?? $creditNote);

            return $creditNote->fresh(['items', 'eInvoiceRecord', 'originalStatutoryInvoice']) ?? $creditNote;
        });

        return [
            'status' => 'issued',
            'message' => 'GST credit note issued against the original statutory invoice.',
            'credit_note_id' => $creditNote->id,
            'credit_note_number' => (string) $creditNote->invoice_number,
            'idempotent' => false,
        ];
    }

    public function idempotencyKeyFor(StatutoryInvoice $original): string
    {
        return self::IDEMPOTENCY_PREFIX.$original->id;
    }

    private function findExistingCreditNote(StatutoryInvoice $original): ?StatutoryInvoice
    {
        return StatutoryInvoice::query()
            ->where('original_statutory_invoice_id', $original->id)
            ->where('document_type', StatutoryInvoiceDocumentType::CreditNote)
            ->where('status', StatutoryInvoiceStatus::Issued)
            ->first();
    }

    private function queueCreditNoteIrnIfEligible(StatutoryInvoice $creditNote): void
    {
        $this->invoices->queueEinvoiceIfEligible($creditNote);
    }
}
