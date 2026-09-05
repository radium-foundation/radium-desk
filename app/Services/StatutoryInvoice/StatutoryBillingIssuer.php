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
 * Service B2B: Maharashtra → Mumbai; any other known Indian state → Delhi.
 * Service B2C: always Delhi.
 *
 * Place of supply is never used to choose the issuer.
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
            return StatutoryLocationSeries::DELHI;
        }

        if (! BuyerGstin::isValid($gstin)) {
            throw ValidationException::withMessages([
                'issuer' => 'B2B service billing requires a valid customer GSTIN. Issuance fails closed.',
            ]);
        }

        $gstinState = BuyerGstin::stateCode($gstin);
        if (! GstStateCodes::isKnownCode($gstinState)) {
            throw ValidationException::withMessages([
                'issuer' => 'B2B service billing requires a known GST state code on the customer GSTIN. Issuance fails closed.',
            ]);
        }

        $named = is_string($customerState) ? trim($customerState) : '';
        if ($named !== '') {
            if (! IndianStates::contains($named)) {
                throw ValidationException::withMessages([
                    'issuer' => 'B2B service customer state is not a recognised Indian state. Issuance fails closed.',
                ]);
            }
            $namedCode = GstStateCodes::codeForName($named);
            if ($namedCode === null || $namedCode !== $gstinState) {
                throw ValidationException::withMessages([
                    'issuer' => 'B2B service customer state does not match the customer GSTIN state. Issuance fails closed.',
                ]);
            }
        }

        return GstStateCodes::isMaharashtraCode($gstinState)
            ? StatutoryLocationSeries::MUMBAI
            : StatutoryLocationSeries::DELHI;
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
