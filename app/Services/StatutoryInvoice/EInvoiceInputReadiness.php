<?php

namespace App\Services\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceInputReadinessResult;
use App\Services\StatutoryInvoice\Data\PlaceOfSupplyResolution;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;

/**
 * Fail-closed IRN input readiness before worker/GENERATE and after mint for B2B.
 */
final class EInvoiceInputReadiness
{
    public function __construct(
        private readonly EInvoiceIrnPayloadMapper $mapper,
        private readonly PlaceOfSupplyResolver $placeOfSupply,
        private readonly EInvoiceEligibility $eligibility,
        private readonly StatutoryInvoiceBillingSnapshot $billingSnapshot,
    ) {}

    public function evaluate(StatutoryInvoice $invoice): EInvoiceInputReadinessResult
    {
        $decision = $this->eligibility->evaluate($invoice);
        if (! $decision->eligible) {
            return new EInvoiceInputReadinessResult(false, [$decision->reason]);
        }

        $blocked = [];
        $pos = $this->placeOfSupply->resolveForInvoice($invoice);
        if (! $pos->isResolvable()) {
            $blocked[] = 'place_of_supply_unresolved';
        }

        $structured = $this->billingSnapshot->structured($invoice);
        if ($structured === null || ! StatutoryBillingStructured::isCompleteForIrn($structured)) {
            $blocked[] = 'missing_billing_address';
        }

        $payload = $this->mapper->map($invoice);
        foreach ($payload->gaps as $gap) {
            if ($gap === 'buyer_state_mismatch') {
                if ($pos->gstinPosClassification === PlaceOfSupplyResolution::CLASSIFICATION_BLOCKED) {
                    $blocked[] = 'buyer_state_mismatch';
                }

                continue;
            }
            $blocked[] = $gap;
        }

        $blocked = array_values(array_unique($blocked));

        return new EInvoiceInputReadinessResult($blocked === [], $blocked);
    }
}
