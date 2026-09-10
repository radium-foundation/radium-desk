<?php

namespace App\Services\StatutoryInvoice\Data;

final class StatutoryInvoicePdfPayload
{
    /**
     * @param  list<array{
     *     description: string,
     *     hsnSac: string,
     *     qty: int,
     *     unitPrice: string,
     *     taxableValue: string,
     *     gstPercentage: string,
     *     cgst: string,
     *     sgst: string,
     *     igst: string,
     *     taxTotal: string,
     *     lineTotal: string,
     *     uqc?: ?string
     * }>  $lines
     * @param  list<string>  $serialNumbers
     */
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly string $issuedAt,
        public readonly string $sellerLegalName,
        public readonly string $sellerGstin,
        public readonly string $sellerAddress,
        public readonly string $sellerState,
        public readonly string $buyerName,
        public readonly ?string $buyerGstin,
        public readonly ?string $billingAddress,
        public readonly string $placeOfSupply,
        public readonly array $lines,
        public readonly string $taxableValue,
        public readonly string $gstRate,
        public readonly string $taxTotal,
        public readonly string $cgst,
        public readonly string $sgst,
        public readonly string $igst,
        public readonly string $invoiceValue,
        public readonly array $serialNumbers = [],
        public readonly ?string $sourceId = null,
        public readonly ?int $fulfilmentId = null,
        public readonly ?string $irn = null,
        public readonly ?string $ackNo = null,
        public readonly ?string $ackDate = null,
        public readonly ?string $shippingAddress = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $paymentStatus = null,
        public readonly ?string $signedQr = null,
        public readonly ?string $sellerEmail = null,
        public readonly ?string $sellerPhone = null,
        public readonly ?string $buyerPhone = null,
        public readonly ?string $buyerEmail = null,
        public readonly ?string $discount = null,
        public readonly ?string $rounding = null,
        public readonly ?string $paymentReference = null,
        public readonly ?string $orderId = null,
    ) {}

    public function hasIssuedIrn(): bool
    {
        return is_string($this->irn) && trim($this->irn) !== '';
    }

    /**
     * Future IRN signed-QR image hook. Never treat a skip/queue payload as an IRN.
     */
    public function hasIssuedSignedQr(): bool
    {
        return $this->hasIssuedIrn() && is_string($this->signedQr) && trim($this->signedQr) !== '';
    }

    public function hasDistinctShippingAddress(): bool
    {
        $shipping = trim((string) $this->shippingAddress);
        if ($shipping === '') {
            return false;
        }

        return strcasecmp($shipping, trim((string) $this->billingAddress)) !== 0;
    }
}
