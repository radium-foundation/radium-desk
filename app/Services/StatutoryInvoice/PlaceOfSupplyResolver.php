<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\PlaceOfSupplyResolution;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Support\Finance\GstStateCodes;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;

/**
 * Evidence-based place of supply. Does not derive POS from GSTIN alone.
 */
final class PlaceOfSupplyResolver
{
    public function resolveForMint(StatutoryInvoiceMintRequest $request): PlaceOfSupplyResolution
    {
        $structured = StatutoryBillingStructured::fromStored($request->billingAddressStructured);
        $buyerGstin = BuyerGstin::normalize($request->buyerGstin);
        $gstinStateCode = BuyerGstin::stateCode($buyerGstin);
        $hasGoods = $this->requestHasGoods($request);
        $hasService = $this->requestHasService($request, $request->channel->value);

        if ($hasGoods && ! $hasService) {
            return $this->resolveGoods(
                $structured,
                $request->placeOfSupplyState,
                $gstinStateCode,
                $request->sellerGstin,
            );
        }

        return $this->resolveService(
            $structured,
            $request->placeOfSupplyState,
            $gstinStateCode,
        );
    }

    public function resolveForInvoice(StatutoryInvoice $invoice): PlaceOfSupplyResolution
    {
        $invoice->loadMissing('items');
        $structured = $this->issuedBillingStructured($invoice);
        $buyerGstin = BuyerGstin::normalize($invoice->buyer_gstin);
        $gstinStateCode = BuyerGstin::stateCode($buyerGstin);
        $hasGoods = $this->invoiceHasGoods($invoice);
        $hasService = $this->invoiceHasService($invoice);

        $storedCode = is_string($invoice->place_of_supply_state_code)
            ? trim($invoice->place_of_supply_state_code)
            : null;
        $storedSource = is_string($invoice->place_of_supply_source)
            ? trim($invoice->place_of_supply_source)
            : null;
        $storedState = is_string($invoice->place_of_supply_state)
            ? trim($invoice->place_of_supply_state)
            : null;

        if ($storedState !== '' && $storedCode !== null && $storedCode !== '' && $storedSource !== null && $storedSource !== '') {
            return $this->classify(
                state: $storedState,
                stateCode: $storedCode,
                source: $storedSource,
                gstinStateCode: $gstinStateCode,
                sellerGstin: $invoice->seller_gstin,
                invoice: $invoice,
            );
        }

        if ($hasGoods && ! $hasService) {
            return $this->resolveGoods(
                $structured,
                $storedState,
                $gstinStateCode,
                $invoice->seller_gstin,
                $invoice,
            );
        }

        return $this->resolveService(
            $structured,
            $storedState,
            $gstinStateCode,
            $invoice,
        );
    }

    /**
     * @param  array<string, mixed>|null  $structured
     */
    private function resolveGoods(
        ?array $structured,
        ?string $transactionPosState,
        ?string $gstinStateCode,
        ?string $sellerGstin,
        ?StatutoryInvoice $invoice = null,
    ): PlaceOfSupplyResolution {
        $destination = $this->goodsDestinationStructured($structured, $invoice);
        $deliveryState = StatutoryBillingStructured::nullable($destination['state'] ?? null);
        if ($deliveryState !== null) {
            $code = GstStateCodes::codeForName($deliveryState);
            if ($code !== null) {
                return $this->classify(
                    state: $deliveryState,
                    stateCode: $code,
                    source: 'goods_delivery_destination',
                    gstinStateCode: $gstinStateCode,
                    sellerGstin: $sellerGstin,
                    invoice: $invoice,
                );
            }
        }

        $transaction = is_string($transactionPosState) ? trim($transactionPosState) : '';
        if ($transaction !== '') {
            $code = GstStateCodes::codeForName($transaction);
            if ($code !== null) {
                return $this->classify(
                    state: $transaction,
                    stateCode: $code,
                    source: 'transaction_billing_address',
                    gstinStateCode: $gstinStateCode,
                    sellerGstin: $sellerGstin,
                    invoice: $invoice,
                );
            }
        }

        return $this->blocked();
    }

    /**
     * @param  array<string, mixed>|null  $structured
     */
    private function resolveService(
        ?array $structured,
        ?string $transactionPosState,
        ?string $gstinStateCode,
        ?StatutoryInvoice $invoice = null,
    ): PlaceOfSupplyResolution {
        $billingState = StatutoryBillingStructured::nullable($structured['state'] ?? null);
        if ($billingState !== null) {
            $code = GstStateCodes::codeForName($billingState);
            if ($code !== null) {
                return $this->classify(
                    state: $billingState,
                    stateCode: $code,
                    source: 'transaction_billing_address',
                    gstinStateCode: $gstinStateCode,
                    sellerGstin: $invoice?->seller_gstin,
                    invoice: $invoice,
                );
            }
        }

        $transaction = is_string($transactionPosState) ? trim($transactionPosState) : '';
        if ($transaction !== '') {
            $code = GstStateCodes::codeForName($transaction);
            if ($code !== null) {
                return $this->classify(
                    state: $transaction,
                    stateCode: $code,
                    source: 'service_specific_rule',
                    gstinStateCode: $gstinStateCode,
                    sellerGstin: $invoice?->seller_gstin,
                    invoice: $invoice,
                );
            }
        }

        return $this->blocked();
    }

    private function classify(
        string $state,
        string $stateCode,
        string $source,
        ?string $gstinStateCode,
        ?string $sellerGstin,
        ?StatutoryInvoice $invoice = null,
    ): PlaceOfSupplyResolution {
        $classification = PlaceOfSupplyResolution::CLASSIFICATION_VALID;
        $confidence = PlaceOfSupplyResolution::CONFIDENCE_VERIFIED;

        if ($gstinStateCode !== null && $gstinStateCode !== $stateCode) {
            $classification = $this->taxConsistentWithPos($invoice, $sellerGstin, $stateCode)
                ? PlaceOfSupplyResolution::CLASSIFICATION_REVIEW
                : PlaceOfSupplyResolution::CLASSIFICATION_BLOCKED;
            $confidence = PlaceOfSupplyResolution::CONFIDENCE_REVIEW;
        }

        return new PlaceOfSupplyResolution(
            state: $state,
            stateCode: $stateCode,
            source: $source,
            confidence: $confidence,
            gstinPosClassification: $classification,
        );
    }

    private function taxConsistentWithPos(?StatutoryInvoice $invoice, ?string $sellerGstin, string $posCode): bool
    {
        if ($invoice === null) {
            return true;
        }

        $sellerCode = BuyerGstin::stateCode(BuyerGstin::normalize($sellerGstin));
        if ($sellerCode === null) {
            return false;
        }

        $intra = $sellerCode === $posCode;
        $hasIgst = (float) ($invoice->igst ?? 0) > 0;
        $hasCgstSgst = (float) ($invoice->cgst ?? 0) > 0 || (float) ($invoice->sgst ?? 0) > 0;

        if ($intra) {
            return $hasCgstSgst && ! $hasIgst;
        }

        return $hasIgst && ! $hasCgstSgst;
    }

    private function blocked(): PlaceOfSupplyResolution
    {
        return new PlaceOfSupplyResolution(
            state: null,
            stateCode: null,
            source: null,
            confidence: PlaceOfSupplyResolution::CONFIDENCE_REVIEW,
            gstinPosClassification: PlaceOfSupplyResolution::CLASSIFICATION_BLOCKED,
        );
    }

    private function requestHasGoods(StatutoryInvoiceMintRequest $request): bool
    {
        foreach ($request->lines as $line) {
            $hsn = is_string($line->hsnSac) ? trim($line->hsnSac) : '';
            if ($hsn !== '' && ! str_starts_with($hsn, '99')) {
                return true;
            }
        }

        return false;
    }

    private function requestHasService(StatutoryInvoiceMintRequest $request, string $channel): bool
    {
        $classifier = app(ServiceStatutoryClassification::class);
        foreach ($request->lines as $line) {
            if ($classifier->profileForCommerceLine(
                $channel,
                $line->sku,
                $line->description,
                $line->hsnSac,
            ) !== null) {
                return true;
            }
        }

        return false;
    }

    private function invoiceHasGoods(StatutoryInvoice $invoice): bool
    {
        foreach ($invoice->items as $item) {
            $hsn = is_string($item->hsn_sac) ? trim($item->hsn_sac) : '';
            if ($hsn !== '' && ! str_starts_with($hsn, '99')) {
                return true;
            }
        }

        return false;
    }

    private function invoiceHasService(StatutoryInvoice $invoice): bool
    {
        $classifier = app(ServiceStatutoryClassification::class);
        foreach ($invoice->items as $item) {
            if ($classifier->profileForInvoiceItem($invoice, $item) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $billingStructured
     * @return array<string, mixed>
     */
    private function goodsDestinationStructured(?array $billingStructured, ?StatutoryInvoice $invoice): array
    {
        if ($invoice !== null) {
            $shipping = $this->commerceShippingStructured($invoice);
            if ($shipping !== null && StatutoryBillingStructured::nullable($shipping['state'] ?? null) !== null) {
                return $shipping;
            }
        }

        return $billingStructured ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function commerceShippingStructured(StatutoryInvoice $invoice): ?array
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

        return StatutoryBillingStructured::fromStored($order?->shipping_address_structured);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function issuedBillingStructured(StatutoryInvoice $invoice): ?array
    {
        $fromInvoice = StatutoryBillingStructured::fromStored($invoice->billing_address_structured);
        if ($fromInvoice !== null) {
            return $fromInvoice;
        }

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

        return StatutoryBillingStructured::fromStored($order?->billing_address_structured);
    }
}
