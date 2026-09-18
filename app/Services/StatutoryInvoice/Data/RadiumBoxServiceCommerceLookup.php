<?php

namespace App\Services\StatutoryInvoice\Data;

final readonly class RadiumBoxServiceCommerceLookup
{
    /**
     * @param  array<string, mixed>|null  $billingAddressStructured
     */
    public function __construct(
        public string $rdOrderId,
        public ?string $billingState,
        public ?string $placeOfSupplyState,
        public ?string $billingAddress,
        public ?array $billingAddressStructured,
        public ?string $buyerGstin,
        public ?string $customerName,
        public ?string $customerEmail,
        public ?string $customerPhone,
        public ?string $serialNo,
        public ?string $serviceName,
        public ?string $productName,
        public ?string $serviceDescription,
        public ?string $catalogHsnSac,
        public ?float $taxableValue,
        public ?float $taxTotal,
        public ?float $lineTotal,
        public ?float $gstPercentage,
        public ?string $orderedAt,
        public ?string $durationType = null,
        public ?float $durationPrice = null,
        public ?float $baseTaxableValue = null,
    ) {}
}
