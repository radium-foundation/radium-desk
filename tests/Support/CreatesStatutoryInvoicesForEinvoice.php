<?php

namespace Tests\Support;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;

trait CreatesStatutoryInvoicesForEinvoice
{
    private int $einvoiceSourceSeq = 0;

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $itemOverrides
     */
    protected function makeTaxInvoice(array $overrides = [], array $itemOverrides = []): StatutoryInvoice
    {
        $seq = ++$this->einvoiceSourceSeq;
        $invoice = StatutoryInvoice::query()->create(array_merge([
            'invoice_number' => 'INV-EINV-'.$seq,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RdServiceNet,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'EINV-SRC-'.$seq,
            'idempotency_key' => 'statutory:einvoice:'.$seq,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer Industries',
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => '1 Test Street, Delhi',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => '100.00',
            'discount' => '0.00',
            'tax_total' => '18.00',
            'cgst' => '9.00',
            'sgst' => '9.00',
            'igst' => '0.00',
            'rounding' => '0.00',
            'invoice_value' => '118.00',
            'issued_at' => '2026-09-10 10:00:00',
        ], $overrides));

        StatutoryInvoiceItem::query()->create(array_merge([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'sku' => 'RD-SVC',
            'description' => 'RD Service',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '100.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '100.00',
            'tax_total' => '18.00',
            'cgst' => '9.00',
            'sgst' => '9.00',
            'igst' => '0.00',
            'line_total' => '118.00',
        ], $itemOverrides));

        return $invoice->fresh(['items']);
    }
}
