<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceLineDraft;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Support\Inventory\PosRetailShippingGst;
use Illuminate\Validation\ValidationException;

/**
 * POS retail shipping as a persisted statutory line (Desk inventory_sale mint path).
 */
final class PosRetailShippingStatutoryLine
{
    /** Internal sentinel — not a catalog SKU. */
    public const LINE_SKU = '__desk_pos_shipping__';

    public function __construct(
        private readonly EInvoiceUqcMapper $uqc,
    ) {}

    public function appendMintShippingLine(StatutoryInvoiceMintRequest $request): StatutoryInvoiceMintRequest
    {
        if ($request->sourceType !== StatutoryInvoiceSourceType::InventorySale) {
            return $request;
        }

        $shipping = round(max(0, $request->shippingAmount), 2);
        if ($shipping <= 0) {
            return $request;
        }

        if (self::hasShippingLine($request)) {
            return $request;
        }

        $maxRate = PosRetailShippingGst::maxLineGstRate($this->productGstRates($request));
        $shippingTax = PosRetailShippingGst::taxOnExclusiveAmount($shipping, $maxRate);

        $description = trim((string) config('statutory_invoices.pos_retail_shipping.description', 'Shipping & Delivery Charges'));
        if ($description === '') {
            $description = 'Shipping & Delivery Charges';
        }

        $hsnSac = $this->resolveHsnSacForMint($request);
        $this->assertHsnSacPresent($hsnSac);
        $uqcCode = trim((string) config('statutory_invoices.pos_retail_shipping.uqc', 'NOS'));
        $uqcSnapshot = $this->uqc->snapshot($uqcCode, null);
        if ($uqcSnapshot === null || trim($uqcSnapshot) === '') {
            throw ValidationException::withMessages([
                'shipping' => 'POS shipping statutory UQC is missing or invalid. Configure STATUTORY_POS_RETAIL_SHIPPING_UQC.',
            ]);
        }

        $draft = new StatutoryInvoiceLineDraft(
            description: $description,
            qty: 1,
            unitPrice: $shipping,
            gstPercentage: $maxRate,
            taxTotal: $shippingTax,
            lineTotal: round($shipping + $shippingTax, 2),
            taxableValue: $shipping,
            discount: 0,
            sku: self::LINE_SKU,
            hsnSac: $hsnSac,
            uqc: $uqcSnapshot,
        );

        return $request->withLines([...$request->lines, $draft]);
    }

    public static function hasShippingLine(StatutoryInvoiceMintRequest $request): bool
    {
        foreach ($request->lines as $line) {
            if ($line->sku === self::LINE_SKU) {
                return true;
            }
        }

        return false;
    }

    public static function invoiceHasShippingLine(StatutoryInvoice $invoice): bool
    {
        $invoice->loadMissing('items');
        foreach ($invoice->items as $item) {
            if ((string) $item->sku === self::LINE_SKU) {
                return true;
            }
        }

        return false;
    }

    /**
     * New mints with a persisted shipping line: line GST and invoice total match header snapshots.
     */
    public static function persistedLinesReconcile(StatutoryInvoice $invoice): bool
    {
        if (! self::invoiceHasShippingLine($invoice)) {
            return false;
        }

        $invoice->loadMissing('items');
        if ($invoice->items->isEmpty()) {
            return false;
        }

        $lineCgst = 0;
        $lineSgst = 0;
        $lineIgst = 0;
        foreach ($invoice->items as $item) {
            $lineCgst += IntraStateCgstSgstRules::moneyPaise((float) $item->cgst);
            $lineSgst += IntraStateCgstSgstRules::moneyPaise((float) $item->sgst);
            $lineIgst += IntraStateCgstSgstRules::moneyPaise((float) $item->igst);
        }

        $headerCgst = IntraStateCgstSgstRules::moneyPaise((float) $invoice->cgst);
        $headerSgst = IntraStateCgstSgstRules::moneyPaise((float) $invoice->sgst);
        $headerIgst = IntraStateCgstSgstRules::moneyPaise((float) $invoice->igst);

        if ($lineCgst !== $headerCgst || $lineSgst !== $headerSgst || $lineIgst !== $headerIgst) {
            return false;
        }

        $shipping = round(max(0, (float) ($invoice->shipping_amount ?? 0)), 2);
        $expectedInvoicePaise = IntraStateCgstSgstRules::moneyPaise((float) $invoice->taxable_value)
            + IntraStateCgstSgstRules::moneyPaise($shipping)
            + IntraStateCgstSgstRules::moneyPaise((float) $invoice->tax_total)
            + IntraStateCgstSgstRules::moneyPaise((float) $invoice->rounding);

        return $expectedInvoicePaise === IntraStateCgstSgstRules::moneyPaise((float) $invoice->invoice_value);
    }

    public function assertClassificationConfigured(): void
    {
        $configured = trim((string) config('statutory_invoices.pos_retail_shipping.hsn_sac'));
        if ($configured !== '') {
            return;
        }

        throw ValidationException::withMessages([
            'shipping' => 'POS shipping statutory HSN/SAC is not configured and could not be derived from product lines. Set STATUTORY_POS_RETAIL_SHIPPING_HSN_SAC or ensure product lines carry HSN/SAC.',
        ]);
    }

    public function resolveHsnSacForMint(StatutoryInvoiceMintRequest $request): string
    {
        $configured = trim((string) config('statutory_invoices.pos_retail_shipping.hsn_sac'));
        if ($configured !== '') {
            return $configured;
        }

        return trim((string) (self::incidentalHsnSacFromLineDrafts($request->lines) ?? ''));
    }

    public static function incidentalHsnSacFromLineDrafts(array $lines): ?string
    {
        $bestHsn = null;
        $bestRate = -1.0;
        foreach ($lines as $line) {
            if (! $line instanceof StatutoryInvoiceLineDraft) {
                continue;
            }
            if ($line->sku === self::LINE_SKU) {
                continue;
            }
            $hsn = trim((string) ($line->hsnSac ?? ''));
            if ($hsn === '') {
                continue;
            }
            $rate = (float) $line->gstPercentage;
            if ($rate > $bestRate) {
                $bestRate = $rate;
                $bestHsn = $hsn;
            }
        }

        return $bestHsn;
    }

    public static function incidentalHsnSacFromInvoiceItems(StatutoryInvoice $invoice): ?string
    {
        $invoice->loadMissing('items');
        $bestHsn = null;
        $bestRate = -1.0;
        foreach ($invoice->items as $item) {
            if ((string) $item->sku === self::LINE_SKU) {
                continue;
            }
            $hsn = trim((string) ($item->hsn_sac ?? ''));
            if ($hsn === '') {
                continue;
            }
            $rate = (float) $item->gst_percentage;
            if ($rate > $bestRate) {
                $bestRate = $rate;
                $bestHsn = $hsn;
            }
        }

        return $bestHsn;
    }

    private function assertHsnSacPresent(string $hsnSac): void
    {
        if (trim($hsnSac) === '') {
            $this->assertClassificationConfigured();
        }
    }

    /**
     * @return list<float>
     */
    private function productGstRates(StatutoryInvoiceMintRequest $request): array
    {
        $rates = [];
        foreach ($request->lines as $line) {
            if ($line->sku === self::LINE_SKU) {
                continue;
            }
            $rates[] = (float) $line->gstPercentage;
        }

        return $rates;
    }
}
