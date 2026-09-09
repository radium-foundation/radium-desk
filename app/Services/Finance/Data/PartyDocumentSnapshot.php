<?php

namespace App\Services\Finance\Data;

final class PartyDocumentSnapshot
{
    /**
     * @param  array{label:?string,kind:string,line1:string,line2:?string,city:?string,district:?string,state:string,state_code:?string,postal_code:string,country:string,landmark:?string}|null  $billingAddress
     * @param  array{label:?string,kind:string,line1:string,line2:?string,city:?string,district:?string,state:string,state_code:?string,postal_code:string,country:string,landmark:?string}|null  $shippingAddress
     */
    public function __construct(
        public readonly int $partyId,
        public readonly string $partyCode,
        public readonly string $legalName,
        public readonly ?string $tradeName,
        public readonly ?string $gstin,
        public readonly ?string $pan,
        public readonly ?array $billingAddress,
        public readonly ?array $shippingAddress,
        public readonly ?string $state,
        public readonly ?string $postalCode,
        public readonly ?string $placeOfSupply,
        public readonly ?string $contactName,
        public readonly ?string $contactPhone,
        public readonly ?string $contactEmail,
    ) {}
}
