<?php

namespace App\Services\StatutoryInvoice\Data;

final class StatutoryInvoicePdfPayload
{
    /**
     * @param  list<array{
     *     description: string,
     *     hsnSac: string,
     *     qty: int,
     *     taxableValue: string,
     *     cgst: string,
     *     sgst: string,
     *     igst: string,
     *     lineTotal: string
     * }>  $lines
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
        public readonly string $cgst,
        public readonly string $sgst,
        public readonly string $igst,
        public readonly string $invoiceValue,
    ) {}
}
