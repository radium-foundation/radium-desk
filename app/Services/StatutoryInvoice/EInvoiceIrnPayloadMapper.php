<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\InventoryProduct;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\Data\EInvoiceIrnPayload;
use App\Services\StatutoryInvoice\Data\PlaceOfSupplyResolution;
use App\Support\Finance\GstStateCodes;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;

/**
 * Provider-independent IRN payload from stored statutory invoice values.
 * Missing IRP fields are listed as gaps. Does not rewrite stored tax or historical lines.
 * Line UQC is the snapshot when populated; catalog UQC is a read-only fallback when the
 * product relationship is unambiguous and the catalog code is mapper-accepted.
 */
class EInvoiceIrnPayloadMapper
{
    public function __construct(
        private readonly StatutorySellerIdentity $sellers,
        private readonly EInvoiceServiceClassification $services,
        private readonly EInvoiceUqcMapper $uqc,
        private readonly ServiceStatutoryClassification $serviceStatutory,
        private readonly PlaceOfSupplyResolver $placeOfSupply,
        private readonly StatutoryAddressFormatter $addressFormatter,
    ) {}

    public function map(StatutoryInvoice $invoice): EInvoiceIrnPayload
    {
        $invoice->loadMissing('items');

        $gaps = EInvoiceStoredGstGuard::missingReasons($invoice);
        $sellerGstin = BuyerGstin::normalize($invoice->seller_gstin);
        $buyerGstin = BuyerGstin::normalize($invoice->buyer_gstin);
        $sellerState = BuyerGstin::stateCode($sellerGstin);
        $buyerStateFromGstin = BuyerGstin::stateCode($buyerGstin);
        $pos = $this->placeOfSupply->resolveForInvoice($invoice);
        $buyerStateFromPlace = $pos->stateCode ?? GstStateCodes::codeForName($invoice->place_of_supply_state);
        $issuedAt = $invoice->issued_at;
        $invoiceNumber = is_string($invoice->invoice_number) ? trim($invoice->invoice_number) : '';
        $seller = $this->sellerFromIssuer($invoice, $sellerGstin, $sellerState);
        $buyer = $this->buyerFromSnapshot($invoice, $buyerGstin, $buyerStateFromGstin, $buyerStateFromPlace, $pos);

        if ($invoiceNumber === '') {
            $gaps[] = 'missing_invoice_number';
        }
        if ($issuedAt === null) {
            $gaps[] = 'missing_invoice_date';
        }
        if ($sellerGstin === null || ! BuyerGstin::isValid($sellerGstin)) {
            $gaps[] = 'missing_seller_gstin';
        }
        if ($buyerGstin === null || ! BuyerGstin::isValid($buyerGstin)) {
            $gaps[] = 'missing_buyer_gstin';
        }
        if ($sellerState === null) {
            $gaps[] = 'missing_seller_stcd';
        }
        if ($buyerStateFromGstin === null) {
            $gaps[] = 'missing_buyer_stcd';
        }
        if (! $pos->isResolvable()) {
            $gaps[] = 'place_of_supply_unresolved';
        }
        if ($buyerStateFromPlace !== null
            && $buyerStateFromGstin !== null
            && $buyerStateFromPlace !== $buyerStateFromGstin
            && $pos->gstinPosClassification === PlaceOfSupplyResolution::CLASSIFICATION_BLOCKED) {
            $gaps[] = 'buyer_state_mismatch';
        }
        $gaps = array_merge($gaps, $seller['gaps'], $buyer['gaps']);

        $items = [];
        foreach ($invoice->items as $item) {
            [$mapped, $itemGaps] = $this->mapItem($invoice, $item);
            $gaps = array_merge($gaps, $itemGaps);
            $items[] = $mapped;
        }

        $documentType = $invoice->document_type;
        $irpType = $documentType === StatutoryInvoiceDocumentType::TaxInvoice ? 'INV' : null;
        if ($irpType === null) {
            $gaps[] = 'unsupported_document_type';
        }

        $gaps = array_values(array_unique($gaps));

        return new EInvoiceIrnPayload(
            supplyType: 'B2B',
            document: [
                'type' => $irpType,
                'number' => $invoiceNumber === '' ? null : $invoiceNumber,
                'date' => $issuedAt?->format('d/m/Y'),
            ],
            seller: $seller['fields'],
            buyer: $buyer['fields'],
            items: $items,
            values: [
                'taxable_value' => $this->storedDecimal($invoice->taxable_value),
                'cgst' => $this->storedDecimal($invoice->cgst),
                'sgst' => $this->storedDecimal($invoice->sgst),
                'igst' => $this->storedDecimal($invoice->igst),
                'tax_total' => $this->storedDecimal($invoice->tax_total),
                'invoice_value' => $this->storedDecimal($invoice->invoice_value),
            ],
            gaps: $gaps,
        );
    }

    /**
     * @return array{fields: array<string, mixed>, gaps: list<string>}
     */
    private function sellerFromIssuer(StatutoryInvoice $invoice, ?string $sellerGstin, ?string $sellerState): array
    {
        $gaps = [];
        $location = $this->sellers->locationForInvoice($invoice) ?? $this->sellers->locationForGstin($sellerGstin);
        $profile = $this->sellers->tryForLocation($location);
        $config = $location !== null
            ? (config('statutory_invoices.location_series.locations.'.$this->sellerLocationKey($location), []) ?: [])
            : [];
        $address = $this->nullable($profile?->address);
        $pin = $this->pin($config['pin'] ?? null);
        $loc = $this->nullable($config['loc'] ?? null);
        $legalName = $this->nullable($invoice->seller_name) ?? $this->nullable($profile?->legalName);

        if ($address === null) {
            $gaps[] = 'missing_seller_address';
        } elseif (strlen($address) > 200) {
            $gaps[] = 'seller_address_exceeds_irp_limit';
        }
        if ($pin === null) {
            $gaps[] = 'missing_seller_pin';
        }
        if ($loc === null) {
            $gaps[] = 'missing_seller_loc';
        }
        if ($legalName === null) {
            $gaps[] = 'missing_seller_name';
        }

        return [
            'fields' => [
                'gstin' => $sellerGstin,
                'legal_name' => $legalName,
                'state_code' => $sellerState,
                'location' => $loc,
                'address' => $address,
                'pin' => $pin,
            ],
            'gaps' => $gaps,
        ];
    }

    /**
     * @return array{fields: array<string, mixed>, gaps: list<string>}
     */
    private function buyerFromSnapshot(
        StatutoryInvoice $invoice,
        ?string $buyerGstin,
        ?string $buyerStateFromGstin,
        ?string $buyerStateFromPlace,
        PlaceOfSupplyResolution $pos,
    ): array {
        $gaps = [];
        $structured = $this->issuedBillingStructured($invoice) ?? [];
        $flatAddress = $this->buyerAddress($invoice, $structured === [] ? null : $structured);
        $formatted = $this->addressFormatter->format(
            $flatAddress,
            $structured === [] ? null : $structured,
        );
        $pin = $this->pin($structured['pincode'] ?? null);
        $loc = $this->nullable($structured['city'] ?? null);
        $legalName = $this->nullable($invoice->buyer_name);
        $posCode = $buyerStateFromPlace;
        $structuredState = $this->nullable($structured['state'] ?? null);

        if ($flatAddress === null && $formatted['combined'] === '') {
            $gaps[] = 'missing_buyer_address';
        } elseif ($formatted['exceeds_limit']) {
            $gaps[] = 'buyer_address_exceeds_irp_limit';
        }
        if ($this->nullable($structured['pincode'] ?? null) !== null && $pin === null) {
            $gaps[] = 'invalid_buyer_pin';
        } elseif ($pin === null) {
            $gaps[] = 'missing_buyer_pin';
        }
        if ($loc === null) {
            $gaps[] = 'missing_buyer_loc';
        }
        if ($structuredState !== null && GstStateCodes::codeForName($structuredState) === null) {
            $gaps[] = 'invalid_buyer_state';
        }
        if ($legalName === null) {
            $gaps[] = 'missing_buyer_name';
        }
        if ($posCode === null) {
            $gaps[] = 'missing_pos_code';
        }

        return [
            'fields' => [
                'gstin' => $buyerGstin,
                'legal_name' => $legalName,
                'state_code' => $buyerStateFromGstin,
                'location' => $loc,
                'address' => $formatted['combined'] !== '' ? $formatted['combined'] : $flatAddress,
                'address_addr1' => $formatted['addr1'],
                'address_addr2' => $formatted['addr2'],
                'pin' => $pin,
                'pos_code' => $posCode,
                'pos_source' => $pos->source,
            ],
            'gaps' => $gaps,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function mapItem(StatutoryInvoice $invoice, StatutoryInvoiceItem $item): array
    {
        $gaps = [];
        $hsn = is_string($item->hsn_sac) ? trim($item->hsn_sac) : '';
        if ($hsn === '') {
            $gaps[] = 'missing_hsn';
        }
        if ((int) $item->qty < 1) {
            $gaps[] = 'missing_qty';
        }
        $serviceProfile = $this->serviceStatutory->profileForInvoiceItem($invoice, $item);
        $uqc = $serviceProfile !== null
            ? $this->uqc->resolveLineOrCatalog($item->getAttribute('uqc') ?? null, $serviceProfile->uqc)
            : $this->uqc->resolveLineOrCatalog(
                $item->getAttribute('uqc') ?? null,
                $this->catalogUqcForItem($invoice, $item),
            );
        if ($uqc['gap'] !== null) {
            $gaps[] = $uqc['gap'];
        }
        $isServc = $serviceProfile?->isServc ?? $this->services->isServc($invoice, $item);
        if ($isServc === null) {
            $gaps[] = 'missing_is_servc';
        }

        return [
            [
                'line_no' => (int) $item->line_no,
                'sku' => $item->sku,
                'description' => $item->description,
                'hsn_sac' => $hsn === '' ? null : $hsn,
                'qty' => (int) $item->qty,
                'unit' => $uqc['code'],
                'is_servc' => $isServc,
                'unit_price' => $this->storedDecimal($item->unit_price),
                'taxable_value' => $this->storedDecimal($item->taxable_value),
                'gst_percentage' => $this->storedDecimal($item->gst_percentage),
                'cgst' => $this->storedDecimal($item->cgst),
                'sgst' => $this->storedDecimal($item->sgst),
                'igst' => $this->storedDecimal($item->igst),
                'tax_total' => $this->storedDecimal($item->tax_total),
                'line_total' => $this->storedDecimal($item->line_total),
            ],
            $gaps,
        ];
    }

    /**
     * Read-only catalog UQC. Does not write statutory_invoice_items.
     * Direct catalog SKU match wins. Otherwise a single Owner channel_sku_maps
     * row for this invoice channel + numeric Box/RIN model id.
     */
    private function catalogUqcForItem(StatutoryInvoice $invoice, StatutoryInvoiceItem $item): ?string
    {
        $sku = is_string($item->sku) ? trim($item->sku) : '';
        if ($sku === '') {
            return null;
        }

        $bySku = InventoryProduct::query()->where('sku', $sku)->get(['id', 'uqc']);
        if ($bySku->count() > 1) {
            return null;
        }
        if ($bySku->count() === 1) {
            $catalog = $bySku->first()?->uqc;

            return is_string($catalog) ? $catalog : null;
        }

        if (! ctype_digit($sku)) {
            return null;
        }

        $channel = $invoice->channel;
        $channelValue = is_object($channel) && isset($channel->value) ? $channel->value : (string) $channel;
        $maps = ChannelSkuMap::query()
            ->where('channel', $channelValue)
            ->where('model_id', (int) $sku)
            ->get(['inventory_product_id']);
        if ($maps->count() !== 1) {
            return null;
        }

        $product = InventoryProduct::query()->find($maps->first()?->inventory_product_id);

        $catalog = $product?->uqc;

        return is_string($catalog) ? $catalog : null;
    }

    /**
     * Mint-time invoice snapshot first. Commerce/POS source snapshots are
     * fallbacks for historical invoices minted before the invoice column existed.
     * Does not read mutable customer profiles.
     *
     * @return array<string, mixed>|null
     */
    private function issuedBillingStructured(StatutoryInvoice $invoice): ?array
    {
        $fromInvoice = StatutoryBillingStructured::fromStored($invoice->billing_address_structured);
        if ($fromInvoice !== null) {
            return $fromInvoice;
        }

        return $this->commerceBillingStructured($invoice)
            ?? $this->posBillingStructured($invoice);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function commerceBillingStructured(StatutoryInvoice $invoice): ?array
    {
        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::CommerceOrder->value) {
            return null;
        }

        $order = CommerceOrder::query()->where('statutory_invoice_id', $invoice->id)->first();
        if ($order === null) {
            $order = CommerceOrder::query()
                ->where('channel', $invoice->channel)
                ->where('source_id', $invoice->source_id)
                ->first();
        }
        if ($order === null) {
            return null;
        }

        $structured = $order->billing_address_structured;

        return StatutoryBillingStructured::fromStored($structured);
    }

    /**
     * Sale-time JSON snapshot. Historical sales remain null and fail closed.
     * Does not read InventoryCustomer.
     *
     * @return array<string, mixed>|null
     */
    private function posBillingStructured(StatutoryInvoice $invoice): ?array
    {
        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::InventorySale->value) {
            return null;
        }

        $invoice->loadMissing('inventorySale');
        $sale = $invoice->inventorySale;
        if ($sale === null) {
            return null;
        }

        return StatutoryBillingStructured::fromStored($sale->billing_address_structured);
    }

    /**
     * @param  array<string, mixed>|null  $structured
     */
    private function buyerAddress(StatutoryInvoice $invoice, ?array $structured): ?string
    {
        $fromInvoice = is_string($invoice->billing_address) ? trim($invoice->billing_address) : '';
        if ($fromInvoice !== '') {
            return $fromInvoice;
        }

        if ($structured === null) {
            return null;
        }

        $line1 = $this->nullable($structured['line1'] ?? null);
        $line2 = $this->nullable($structured['line2'] ?? null);
        $joined = trim(implode(', ', array_filter([$line1, $line2])));

        return $joined === '' ? null : $joined;
    }

    private function sellerLocationKey(string $location): string
    {
        return $location === 'delhi_b2c' ? 'delhi' : $location;
    }

    private function pin(mixed $value): ?string
    {
        $string = $this->nullable($value);
        if ($string === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $string) ?? '';
        if (strlen($digits) !== 6) {
            return null;
        }

        return $digits;
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function storedDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
