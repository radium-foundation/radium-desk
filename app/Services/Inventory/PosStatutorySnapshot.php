<?php

namespace App\Services\Inventory;

use App\Services\StatutoryInvoice\BuyerGstin;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use App\Support\Finance\GstStateCodes;
use App\Support\Finance\IndianStates;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Validation\ValidationException;

final class PosStatutorySnapshot
{
    /**
     * @param  array<string, mixed>  $statutory
     * @return array{
     *     buyer_gstin: ?string,
     *     billing_address: ?string,
     *     place_of_supply_state: ?string,
     *     billing_address_structured: ?array<string, string>
     * }
     */
    public function capture(?string $customerGstin, array $statutory, ?string $branchCode = null): array
    {
        $fromForm = array_key_exists('buyer_gstin', $statutory);
        $buyerGstin = BuyerGstin::normalize(
            $fromForm
                ? StatutoryBillingStructured::nullable($statutory['buyer_gstin'] ?? null)
                : StatutoryBillingStructured::nullable($customerGstin)
        );
        if ($buyerGstin !== null && ! BuyerGstin::isValid($buyerGstin)) {
            throw ValidationException::withMessages([
                'buyer_gstin' => 'Enter a valid 15-character GSTIN or leave it blank for B2C.',
            ]);
        }

        $place = StatutoryBillingStructured::nullable($statutory['place_of_supply_state'] ?? null);
        if ($place === null && $buyerGstin === null) {
            $place = $this->defaultWalkInPlaceOfSupply($branchCode);
        }
        if ($place !== null && ! IndianStates::contains($place)) {
            throw ValidationException::withMessages([
                'place_of_supply_state' => 'Select a valid Indian place of supply state.',
            ]);
        }

        $line1 = StatutoryBillingStructured::nullable($statutory['billing_address'] ?? null);
        $city = StatutoryBillingStructured::nullable($statutory['billing_city'] ?? null);
        $state = StatutoryBillingStructured::nullable($statutory['billing_state'] ?? null);
        $pincodeRaw = StatutoryBillingStructured::nullable($statutory['billing_pincode'] ?? null);

        if ($state !== null && ! IndianStates::contains($state)) {
            throw ValidationException::withMessages([
                'billing_state' => 'Select a valid Indian billing state.',
            ]);
        }

        if ($pincodeRaw !== null && StatutoryBillingStructured::pin($pincodeRaw) === null) {
            throw ValidationException::withMessages([
                'billing_pincode' => 'Enter a valid 6-digit PIN code.',
            ]);
        }

        $structured = StatutoryBillingStructured::fromParts($line1, $city, $state, $pincodeRaw);

        if ($buyerGstin !== null) {
            $this->assertB2bAddress($buyerGstin, $structured, $place, $city, $state, $pincodeRaw);
        }

        return [
            'buyer_gstin' => $buyerGstin,
            'billing_address' => $line1,
            'place_of_supply_state' => $place,
            'billing_address_structured' => $structured,
        ];
    }

    /**
     * @param  array<string, string>|null  $structured
     */
    private function assertB2bAddress(
        string $buyerGstin,
        ?array $structured,
        ?string $place,
        ?string $city,
        ?string $state,
        ?string $pincodeRaw,
    ): void {
        $errors = [];
        if ($city === null) {
            $errors['billing_city'] = 'City is required for B2B sales.';
        }
        if ($state === null) {
            $errors['billing_state'] = 'State is required for B2B sales.';
        }
        if ($pincodeRaw === null) {
            $errors['billing_pincode'] = 'PIN is required for B2B sales.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if (! StatutoryBillingStructured::isCompleteForIrn($structured)) {
            throw ValidationException::withMessages([
                'billing_pincode' => 'City, state, and a valid PIN are required for B2B sales.',
            ]);
        }

        if ($place !== null && $state !== null && $place !== $state) {
            throw ValidationException::withMessages([
                'billing_state' => 'Billing state must match the selected place of supply.',
            ]);
        }

        $gstinState = BuyerGstin::stateCode($buyerGstin);
        $billingCode = $state !== null ? GstStateCodes::codeForName($state) : null;
        if ($gstinState !== null && $billingCode !== null && $gstinState !== $billingCode) {
            throw ValidationException::withMessages([
                'billing_state' => 'Billing state must match the GSTIN registered state.',
            ]);
        }
    }

    private function defaultWalkInPlaceOfSupply(?string $branchCode): ?string
    {
        $location = app(StatutoryLocationSeries::class)->resolveFromBranchCode($branchCode);
        if ($location === null) {
            return null;
        }

        $state = config('statutory_invoices.location_series.locations.'.$location.'.state');

        return is_string($state) && trim($state) !== '' ? trim($state) : null;
    }
}
