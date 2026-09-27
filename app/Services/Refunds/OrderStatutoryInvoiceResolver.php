<?php

namespace App\Services\Refunds;

use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Services\Refunds\Data\OrderStatutoryInvoiceResolution;
use App\Services\StatutoryInvoice\StatutoryInvoiceForIncidentResolver;

/**
 * Resolves authoritative statutory tax invoices for Desk support orders linked to refunds.
 *
 * POS statutory invoices are explicitly out of scope for automatic v1 adjustment.
 */
final class OrderStatutoryInvoiceResolver
{
    public function __construct(
        private readonly StatutoryInvoiceForIncidentResolver $invoicesForOrder,
    ) {}

    public function resolveForOrder(Order $order): OrderStatutoryInvoiceResolution
    {
        $invoices = $this->invoicesForOrder->forOrder($order);

        if ($invoices->isEmpty()) {
            return OrderStatutoryInvoiceResolution::skipped('no_linked_statutory_invoice');
        }

        $issued = $invoices->filter(
            fn (StatutoryInvoice $invoice): bool => $invoice->document_type === StatutoryInvoiceDocumentType::TaxInvoice,
        );

        if ($issued->isEmpty()) {
            return OrderStatutoryInvoiceResolution::skipped('no_tax_invoice');
        }

        if ($issued->count() > 1) {
            return OrderStatutoryInvoiceResolution::skipped('ambiguous_multiple_invoices');
        }

        $invoice = $issued->first();

        if ($invoice === null) {
            return OrderStatutoryInvoiceResolution::skipped('no_tax_invoice');
        }

        if ($invoice->inventory_sale_id !== null
            || $invoice->source_type === StatutoryInvoiceSourceType::InventorySale->value) {
            return OrderStatutoryInvoiceResolution::skipped('pos_statutory_boundary');
        }

        return OrderStatutoryInvoiceResolution::found($invoice);
    }
}
