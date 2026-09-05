<?php

namespace App\Services\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\StatutorySellerProfile;
use App\Support\Finance\GstStateCodes;
use Illuminate\Validation\ValidationException;

/**
 * Resolves Desk seller identity from the selected billing issuer.
 *
 * Legal name is company-level. GSTIN, registered address, and seller state
 * are Delhi / Mumbai issuer fields. A single global GSTIN is not used.
 */
final class StatutorySellerIdentity
{
    public function __construct(
        private readonly StatutoryLocationSeries $locations,
    ) {}

    public function requireForLocation(string $location): StatutorySellerProfile
    {
        $legalName = $this->legalName();
        if ($legalName === null) {
            throw ValidationException::withMessages([
                'seller' => 'Desk seller legal name is unset.',
            ]);
        }

        $config = $this->locationConfig($location);
        $gstin = BuyerGstin::normalize($config['gstin'] !== '' ? $config['gstin'] : null);
        if ($gstin === null || ! BuyerGstin::isValid($gstin)) {
            throw ValidationException::withMessages([
                'seller' => 'Desk seller GSTIN is unset for the '.$location.' billing issuer.',
            ]);
        }

        $expectedState = $this->locations->gstStateCode($location);
        if (BuyerGstin::stateCode($gstin) !== $expectedState) {
            throw ValidationException::withMessages([
                'seller' => 'Desk seller GSTIN does not match the '.$location.' GST state. Issuance fails closed.',
            ]);
        }

        $address = $this->nullable($config['address']);
        if ($address === null) {
            throw ValidationException::withMessages([
                'seller' => 'Desk seller registered address is unset for the '.$location.' billing issuer.',
            ]);
        }

        $state = $this->nullable($config['state']) ?? GstStateCodes::nameForCode($expectedState);
        if ($state === null) {
            throw ValidationException::withMessages([
                'seller' => 'Desk seller state is unset for the '.$location.' billing issuer.',
            ]);
        }

        $namedCode = GstStateCodes::codeForName($state);
        if ($namedCode !== null && $namedCode !== $expectedState) {
            throw ValidationException::withMessages([
                'seller' => 'Desk seller state does not match the '.$location.' GST state. Issuance fails closed.',
            ]);
        }

        return new StatutorySellerProfile(
            location: $location,
            legalName: $legalName,
            gstin: $gstin,
            address: $address,
            state: $state,
        );
    }

    public function errorForLocation(?string $location): ?string
    {
        if ($location === null || trim($location) === '') {
            return 'Statutory billing issuer is unset. Issuance fails closed.';
        }

        try {
            $this->requireForLocation($location);
        } catch (ValidationException $exception) {
            return $this->firstMessage($exception);
        }

        return null;
    }

    public function locationForInvoice(StatutoryInvoice $invoice): ?string
    {
        $invoice->loadMissing('allocation.sequence');
        $scope = $invoice->allocation?->sequence?->gstin_scope;
        if (is_string($scope) && str_starts_with($scope, 'location:')) {
            $location = substr($scope, strlen('location:'));
            if ($this->locations->isKnown($location)) {
                return $location;
            }
        }

        return $this->locationForGstin($invoice->seller_gstin);
    }

    public function locationForGstin(?string $gstin): ?string
    {
        $gstin = BuyerGstin::normalize($gstin);
        if ($gstin === null) {
            return null;
        }

        foreach ($this->locations->locations() as $location => $config) {
            $configured = BuyerGstin::normalize($config['gstin'] !== '' ? $config['gstin'] : null);
            if ($configured !== null && $configured === $gstin) {
                return $location;
            }
        }

        return null;
    }

    public function tryForLocation(?string $location): ?StatutorySellerProfile
    {
        if ($location === null) {
            return null;
        }

        try {
            return $this->requireForLocation($location);
        } catch (ValidationException) {
            return null;
        }
    }

    public function legalName(): ?string
    {
        return $this->nullable(config('statutory_invoices.legal_name'));
    }

    /**
     * @return array{gst_state_code: string, branch_codes: list<string>, gstin: string, address: string, state: string}
     */
    private function locationConfig(string $location): array
    {
        $locations = $this->locations->locations();
        if (! isset($locations[$location])) {
            throw ValidationException::withMessages([
                'seller' => 'Unsupported statutory GST registration. Only Delhi and Mumbai issuers are configured.',
            ]);
        }

        return $locations[$location];
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function firstMessage(ValidationException $exception): string
    {
        $errors = $exception->errors();
        $first = reset($errors);

        return is_array($first) ? (string) reset($first) : $exception->getMessage();
    }
}
