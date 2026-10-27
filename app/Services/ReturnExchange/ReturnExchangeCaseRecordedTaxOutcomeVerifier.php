<?php

namespace App\Services\ReturnExchange;

use App\Enums\PosReturnRefundTaxEvidenceType;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Models\ReturnExchangeCase;
use App\Models\StatutoryInvoice;
use App\Services\ReturnExchange\Data\PosReturnRefundTaxEvidenceVerification;

/**
 * Verifies Desk-recorded tax outcomes (cancellation or credit note) for return-case refund submission.
 */
final class ReturnExchangeCaseRecordedTaxOutcomeVerifier
{
    public function __construct(
        private readonly ReturnExchangeCaseTaxEvidenceResolver $taxEvidenceResolver,
    ) {}

    public function verify(ReturnExchangeCase $case): PosReturnRefundTaxEvidenceVerification
    {
        $evidence = $this->taxEvidenceResolver->forCase($case);

        if ($evidence->linkState === 'not_linked') {
            return PosReturnRefundTaxEvidenceVerification::blocked(
                $evidence->linkMessage.' Finance/CA review is required before a refund request can be submitted.',
            );
        }

        if ($evidence->linkState === 'ambiguous') {
            return PosReturnRefundTaxEvidenceVerification::blocked(
                $evidence->linkMessage,
            );
        }

        if (count($evidence->invoices) !== 1) {
            return PosReturnRefundTaxEvidenceVerification::blocked(
                'Statutory tax evidence could not be verified for this case. Finance/CA review is required.',
            );
        }

        $invoiceNumber = $evidence->invoices[0]->invoiceNumber;
        $invoice = $this->resolveInvoice($case, $invoiceNumber);

        if ($invoice === null) {
            return PosReturnRefundTaxEvidenceVerification::blocked(
                'Linked statutory invoice could not be loaded. Finance/CA review is required.',
            );
        }

        $invoice->loadMissing(['cancellation', 'creditNotes']);

        if ($invoice->cancellation !== null) {
            return PosReturnRefundTaxEvidenceVerification::verified(
                $invoice,
                PosReturnRefundTaxEvidenceType::Cancellation,
            );
        }

        $creditNotes = $invoice->creditNotes
            ->filter(fn (StatutoryInvoice $cn): bool => $cn->document_type === StatutoryInvoiceDocumentType::CreditNote);

        if ($creditNotes->isNotEmpty()) {
            return PosReturnRefundTaxEvidenceVerification::verified(
                $invoice,
                PosReturnRefundTaxEvidenceType::CreditNote,
            );
        }

        return PosReturnRefundTaxEvidenceVerification::blocked(
            'No statutory cancellation or linked credit note is recorded for invoice '
            .$invoice->invoice_number.'. Finance/CA must complete tax outcome before refund request.',
        );
    }

    private function resolveInvoice(ReturnExchangeCase $case, string $invoiceNumber): ?StatutoryInvoice
    {
        $case->loadMissing(['inventorySale', 'hardwareFulfilment', 'order']);

        $query = StatutoryInvoice::query()->where('invoice_number', $invoiceNumber);

        if ($case->inventory_sale_id !== null) {
            $query->where(function ($inner) use ($case): void {
                $inner->where('inventory_sale_id', $case->inventory_sale_id)
                    ->orWhere('id', $case->inventorySale?->statutory_invoice_id);
            });
        } elseif ($case->hardware_fulfilment_id !== null) {
            $query->where(function ($inner) use ($case): void {
                $inner->where('id', $case->hardwareFulfilment?->statutory_invoice_id)
                    ->orWhere('inventory_sale_id', $case->hardwareFulfilment?->statutory_invoice_id);
            });
            if ($case->order_id !== null) {
                $query->orWhere('support_order_id', $case->order_id);
            }
        } elseif ($case->order_id !== null) {
            $query->where('support_order_id', $case->order_id);
        }

        $matches = $query->get();

        return $matches->count() === 1 ? $matches->first() : StatutoryInvoice::query()
            ->where('invoice_number', $invoiceNumber)
            ->when($case->order_id !== null, fn ($q) => $q->where('support_order_id', $case->order_id))
            ->first();
    }
}
