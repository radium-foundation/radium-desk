<?php

namespace App\Services\Pos;

use App\Models\InventoryBranch;
use App\Services\Pos\Data\WalkInPlaceOfSupplyResolution;
use App\Services\StatutoryInvoice\StatutoryBillingIssuer;
use App\Services\StatutoryInvoice\StatutorySellerIdentity;
use App\Support\Finance\GstStateCodes;
use App\Support\Finance\IndianStates;
use Illuminate\Validation\ValidationException;

/**
 * Walk-in retail goods: place of supply follows handover/delivery context,
 * not GSTIN prefix alone.
 */
final class WalkInPlaceOfSupplyResolver
{
    public const SOURCE_WALK_IN_HANDOVER = 'walk_in_handover';

    public const SOURCE_DELIVERY_STATE = 'delivery_state';

    public const SOURCE_OPERATOR_CONFIRMED = 'operator_confirmed';

    public function __construct(
        private readonly StatutoryBillingIssuer $issuer,
        private readonly StatutorySellerIdentity $seller,
    ) {}

    public function resolveForWalkInSale(
        InventoryBranch $branch,
        ?string $operatorPlace,
        ?string $deliveryState,
    ): WalkInPlaceOfSupplyResolution {
        $sellerState = $this->sellerStateForBranch($branch);
        $delivery = $this->normaliseState($deliveryState);
        if ($delivery !== null) {
            return new WalkInPlaceOfSupplyResolution($delivery, self::SOURCE_DELIVERY_STATE);
        }

        $operator = $this->normaliseState($operatorPlace);
        $place = $operator ?? $sellerState;

        if ($place !== $sellerState) {
            throw ValidationException::withMessages([
                'place_of_supply_state' => 'Place of supply does not match walk-in handover at '
                    .$sellerState.'. Confirm delivery state or set place of supply to the selling branch state.',
            ]);
        }

        return new WalkInPlaceOfSupplyResolution(
            $place,
            $operator !== null ? self::SOURCE_OPERATOR_CONFIRMED : self::SOURCE_WALK_IN_HANDOVER,
        );
    }

    public function sellerStateForBranch(InventoryBranch $branch): string
    {
        $location = $this->issuer->requireForProductBranch($branch->code);
        $profile = $this->seller->requireForLocation($location);

        return trim($profile->state);
    }

    private function normaliseState(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (! IndianStates::contains($value)) {
            throw ValidationException::withMessages([
                'place_of_supply_state' => 'Select a valid Indian place of supply state.',
            ]);
        }

        if (GstStateCodes::codeForName($value) === null) {
            throw ValidationException::withMessages([
                'place_of_supply_state' => 'Place of supply is not a recognised Indian GST state.',
            ]);
        }

        return $value;
    }
}
