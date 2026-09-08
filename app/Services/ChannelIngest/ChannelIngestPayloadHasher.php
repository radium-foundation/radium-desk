<?php

namespace App\Services\ChannelIngest;

use App\Enums\StatutoryInvoiceChannel;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;
use App\Services\HardwareFulfilment\HardwareHandoffTenderContract;

/**
 * Canonical payload identity for channel ingest retries.
 *
 * Service channels keep the historical hash so existing service retries stay 200.
 * radiumbox_com uses the hardware hash: business fields + canonical metadata,
 * excluding volatile transport timestamps.
 */
class ChannelIngestPayloadHasher
{
    /**
     * Business metadata that may participate in hardware payload identity.
     * Volatile retry / transport keys are never hashed, even if present.
     *
     * @var list<string>
     */
    public const HARDWARE_METADATA_IDENTITY_KEYS = [
        'radiumbox_order_id',
        'ordertype',
        'order_type',
    ];

    /**
     * @var list<string>
     */
    public const VOLATILE_METADATA_KEY_PATTERNS = [
        '/password|secret|token|authorization|api[_-]?key/i',
        '/retry|attempt|timestamp|enqueued|nonce|request_id|paid_at|ordered_at/i',
        '/cashfree_payment_id|cf_payment|payment_id/i',
    ];

    public function hash(ChannelOrderIngestRequest $request): string
    {
        $payload = $request->channel === StatutoryInvoiceChannel::RadiumBoxCom
            ? $this->hardwareCanonical($request)
            : $this->serviceCanonical($request);

        return hash('sha256', (string) json_encode($payload));
    }

    public function matchesStored(string $stored, ChannelOrderIngestRequest $request): bool
    {
        return hash_equals($stored, $this->hash($request));
    }

    /**
     * Historical service hash. Do not add fields here.
     *
     * @return array<string, mixed>
     */
    public function serviceCanonical(ChannelOrderIngestRequest $request): array
    {
        return [
            'channel' => $request->channel->value,
            'source_type' => $request->sourceType->value,
            'source_id' => $request->sourceId,
            'source_order_id' => $request->sourceOrderId,
            'payment_status' => $request->paymentStatus,
            'payment_provider' => $request->paymentProvider,
            'payment_reference' => $request->paymentReference,
            'payment_method' => $request->paymentMethod,
            'currency' => $request->currency,
            'customer_name' => $request->customerName,
            'customer_phone' => $request->customerPhone,
            'customer_email' => $request->customerEmail,
            'buyer_gstin' => $request->buyerGstin,
            'billing_address' => $request->billingAddress,
            'billing_state' => $request->billingState,
            'shipping_address' => $request->shippingAddress,
            'seller_gstin' => $request->sellerGstin,
            'seller_name' => $request->sellerName,
            'branch_code' => $request->branchCode,
            'place_of_supply_state' => $request->placeOfSupplyState,
            'discount' => $request->discount,
            'lines' => array_map(fn (ChannelOrderLineDraft $line): array => [
                'sku' => $line->sku,
                'variant' => $line->variant,
                'description' => $line->description,
                'hsn_sac' => $line->hsnSac,
                'qty' => $line->qty,
                'unit_price' => $line->unitPrice,
                'discount' => $line->discount,
                'gst_percentage' => $line->gstPercentage,
                'taxable_value' => $line->taxableValue,
                'tax_total' => $line->taxTotal,
                'cgst' => $line->cgst,
                'sgst' => $line->sgst,
                'igst' => $line->igst,
                'line_total' => $line->lineTotal,
            ], $request->lines),
        ];
    }

    /**
     * Hardware business identity. Excludes paid_at, ordered_at, HMAC timestamps,
     * support_order_id (Cashfree correlation, persisted separately), and volatile metadata.
     *
     * @return array<string, mixed>
     */
    public function hardwareCanonical(ChannelOrderIngestRequest $request): array
    {
        $canonical = [
            'channel' => $request->channel->value,
            'source_type' => $request->sourceType->value,
            'source_id' => $request->sourceId,
            'source_order_id' => $request->sourceOrderId,
            'payment_status' => $request->paymentStatus,
            'payment_provider' => $request->paymentProvider,
            'payment_reference' => $request->paymentReference,
            'payment_method' => $request->paymentMethod,
            'currency' => $request->currency,
            'customer_name' => $request->customerName,
            'customer_phone' => $request->customerPhone,
            'customer_email' => $request->customerEmail,
            'buyer_gstin' => $request->buyerGstin,
            'billing_address' => $request->billingAddress,
            'billing_state' => $request->billingState,
            'billing_address_structured' => $this->canonicalAddress($request->billingAddressStructured),
            'shipping_address' => $request->shippingAddress,
            'shipping_address_structured' => $this->canonicalAddress($request->shippingAddressStructured),
            'parcel' => $this->canonicalParcel($request->parcel),
            'seller_gstin' => $request->sellerGstin,
            'seller_name' => $request->sellerName,
            'branch_code' => $request->branchCode,
            'place_of_supply_state' => $request->placeOfSupplyState,
            'discount' => $request->discount,
            'metadata' => $this->canonicalHardwareMetadata($request->metadata),
            'lines' => array_map(fn (ChannelOrderLineDraft $line): array => [
                'sku' => $line->sku,
                'variant' => $line->variant,
                'description' => $line->description,
                'hsn_sac' => $line->hsnSac,
                'qty' => $line->qty,
                'unit_price' => $line->unitPrice,
                'discount' => $line->discount,
                'gst_percentage' => $line->gstPercentage,
                'taxable_value' => $line->taxableValue,
                'tax_total' => $line->taxTotal,
                'cgst' => $line->cgst,
                'sgst' => $line->sgst,
                'igst' => $line->igst,
                'line_total' => $line->lineTotal,
                'shipping_line_kind' => $line->shippingLineKind,
                'requires_shipping' => $line->requiresShipping,
                'product_id' => $line->productId,
                'model_id' => $line->modelId,
                'catalog_sku' => $line->catalogSku,
                'rdserviceid' => $line->rdserviceid,
                'amcid' => $line->amcid,
                'otgid' => $line->otgid,
            ], $request->lines),
        ];

        $tenders = (new HardwareHandoffTenderContract)->canonical($request);
        if ($tenders !== []) {
            $canonical['tenders'] = $tenders;
        }

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, scalar|null>
     */
    public function canonicalHardwareMetadata(array $metadata): array
    {
        $out = [];
        foreach (self::HARDWARE_METADATA_IDENTITY_KEYS as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }
            if ($this->isVolatileMetadataKey($key)) {
                continue;
            }
            $value = $metadata[$key];
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }
            $out[$key] = $value;
        }
        ksort($out);

        return $out;
    }

    public function isVolatileMetadataKey(string $key): bool
    {
        foreach (self::VOLATILE_METADATA_KEY_PATTERNS as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $address
     * @return array<string, string>|null
     */
    public function canonicalAddress(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $out = [];
        foreach (['line1', 'line2', 'city', 'state', 'pincode', 'country'] as $key) {
            if (! array_key_exists($key, $address)) {
                continue;
            }
            $value = $address[$key];
            if (! is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $out[$key] = $value;
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, mixed>|null  $parcel
     * @return array<string, mixed>|null
     */
    public function canonicalParcel(?array $parcel): ?array
    {
        if ($parcel === null) {
            return null;
        }

        $out = [];
        foreach (['weight', 'length', 'breadth', 'width', 'height', 'weight_unit'] as $key) {
            if (! array_key_exists($key, $parcel)) {
                continue;
            }
            $value = $parcel[$key];
            if (! is_scalar($value)) {
                continue;
            }
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }
            $out[$key] = is_numeric($value) ? $value + 0 : $value;
        }

        return $out === [] ? null : $out;
    }
}
