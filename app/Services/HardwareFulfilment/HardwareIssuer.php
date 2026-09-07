<?php

namespace App\Services\HardwareFulfilment;

use App\Services\StatutoryInvoice\BuyerGstin;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use Illuminate\Validation\ValidationException;

/**
 * Hardware issuer is physical fulfilment branch + buyer GSTIN only.
 * Customer state and place of supply are not inputs.
 * Does not call StatutorySupplyKindResolver or requireForCommerceOrder.
 */
final class HardwareIssuer
{
    public function __construct(
        private readonly StatutoryLocationSeries $locations,
    ) {}

    public function require(?string $fulfilmentBranchCode, ?string $buyerGstin): string
    {
        $base = $this->locations->requireFromBranchCode($fulfilmentBranchCode);
        $gstin = BuyerGstin::normalize($buyerGstin);

        if ($gstin !== null && ! BuyerGstin::isValid($gstin)) {
            throw ValidationException::withMessages([
                'issuer' => 'Hardware B2B billing requires a valid 15-character customer GSTIN. Invalid GSTIN values cannot be treated as B2C. Issuance fails closed.',
            ]);
        }

        if ($base === StatutoryLocationSeries::MUMBAI) {
            return StatutoryLocationSeries::MUMBAI;
        }

        return $gstin === null
            ? StatutoryLocationSeries::DELHI_B2C
            : StatutoryLocationSeries::DELHI;
    }
}
