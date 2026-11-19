<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoice;

/**
 * Footer remark for B2C invoices issued after authorized GST verification B2C conversion only.
 */
final class StatutoryInvoiceB2cFooterRemarkResolver
{
    public function __construct(
        private readonly CommerceOrderBuyerGstB2cConversionService $conversions,
    ) {}

    public function forInvoice(StatutoryInvoice $invoice): ?string
    {
        $buyerGstin = is_string($invoice->buyer_gstin) ? trim($invoice->buyer_gstin) : '';
        if ($buyerGstin !== '') {
            return null;
        }

        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::CommerceOrder->value) {
            return null;
        }

        $order = CommerceOrder::query()
            ->where('channel', $invoice->channel)
            ->where('source_id', $invoice->source_id)
            ->first();
        if ($order === null) {
            return null;
        }

        $conversion = $this->conversions->approvedConversion($order);
        if ($conversion === null) {
            return null;
        }

        $remark = trim((string) $conversion->invoice_remark);

        return $remark !== '' ? $remark : null;
    }
}
