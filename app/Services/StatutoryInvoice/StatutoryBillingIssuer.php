<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutorySupplyKind;
use App\Support\Finance\GstStateCodes;
use App\Support\Finance\IndianStates;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the statutory billing issuer only.
 *
 * Product: branch → Delhi / Mumbai.
 * Service B2B: valid GSTIN state 27 → Mumbai; any other known GSTIN state → Delhi B2B.
 * Service B2C: billing_state Maharashtra → Mumbai; any other recognised Indian state → Delhi B2C.
 *
 * Invalid non-empty GSTIN fails closed (never treated as B2C).
 * Missing or unrecognised B2C billing_state fails closed.
 * Place of supply is never used to choose the issuer.
 * B2B issuer follows the GSTIN state; billing_state does not override it.
 */
final class StatutoryBillingIssuer
{
    public function __construct(
        private readonly StatutoryLocationSeries $locations,
        private readonly StatutorySupplyKindResolver $kinds,
    ) {}

    public function requireForProductBranch(?string $branchCode): string
    {
        return $this->locations->requireFromBranchCode($branchCode);
    }

    public function requireForCommerceOrder(?string $branchCode, ?string $buyerGstin, ?string $customerState, array $hsnSacs): string
    {
        $kind = $this->kinds->requireFromLines($hsnSacs);

        return $this->require($kind, $branchCode, $buyerGstin, $customerState);
    }

    public function require(
        StatutorySupplyKind $kind,
        ?string $branchCode,
        ?string $buyerGstin,
        ?string $customerState,
    ): string {
        if ($kind === StatutorySupplyKind::Product) {
            return $this->requireForProductBranch($branchCode);
        }

        $gstin = BuyerGstin::normalize($buyerGstin);
        if ($gstin === null) {
            return $this->requireB2cServiceLocation($customerState);
        }

        if (! BuyerGstin::isValid($gstin)) {
            throw ValidationException::withMessages([
                'issuer' => 'B2B service billing requires a valid 15-character customer GSTIN. Invalid GSTIN values cannot be treated as B2C. Issuance fails closed.',
            ]);
        }

        $gstinState = BuyerGstin::stateCode($gstin);
        if (! GstStateCodes::isKnownCode($gstinState)) {
            throw ValidationException::withMessages([
                'issuer' => 'B2B service billing requires a known GST state code on the customer GSTIN. Issuance fails closed.',
            ]);
        }

        return GstStateCodes::isMaharashtraCode($gstinState)
            ? StatutoryLocationSeries::MUMBAI
            : StatutoryLocationSeries::DELHI;
    }

    private function requireB2cServiceLocation(?string $billingState): string
    {
        $named = is_string($billingState) ? trim($billingState) : '';
        if ($named === '') {
            throw ValidationException::withMessages([
                'issuer' => 'B2C service billing requires a recognised billing_state. Place of supply cannot substitute. Issuance fails closed.',
            ]);
        }

        if (! IndianStates::contains($named)) {
            throw ValidationException::withMessages([
                'issuer' => 'B2C service billing_state is not a recognised Indian state. Issuance fails closed.',
            ]);
        }

        return GstStateCodes::isMaharashtraName($named)
            ? StatutoryLocationSeries::MUMBAI
            : StatutoryLocationSeries::DELHI_B2C;
    }

    public function errorForSale(?string $branchCode): ?string
    {
        try {
            $this->requireForProductBranch($branchCode);
        } catch (ValidationException $exception) {
            return $this->firstMessage($exception);
        }

        return null;
    }

    public function errorForCommerceOrder(?string $branchCode, ?string $buyerGstin, ?string $customerState, array $hsnSacs): ?string
    {
        try {
            $this->requireForCommerceOrder($branchCode, $buyerGstin, $customerState, $hsnSacs);
        } catch (ValidationException $exception) {
            return $this->firstMessage($exception);
        }

        return null;
    }

    private function firstMessage(ValidationException $exception): string
    {
        $errors = $exception->errors();
        $first = reset($errors);

        return is_array($first) ? (string) reset($first) : $exception->getMessage();
    }
}
