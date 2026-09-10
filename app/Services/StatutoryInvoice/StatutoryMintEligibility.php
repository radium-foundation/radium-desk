<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\InventorySaleStatus;
use App\Models\CommerceOrder;
use App\Models\InventorySale;
use App\Services\HardwareFulfilment\HardwareCommerceStatutoryInvoiceGuard;
use App\Services\StatutoryInvoice\Data\StatutoryMintEligibilityResult;
use App\Support\Finance\GstStateCodes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class StatutoryMintEligibility
{
    public const PLACE_OF_SUPPLY_MISSING = 'Place of supply is missing.';

    public function __construct(
        private readonly StatutoryInvoiceNumberingService $numbering,
        private readonly StatutoryBillingIssuer $issuer,
        private readonly StatutorySellerIdentity $seller,
        private readonly StatutoryLocationSeries $locations,
        private readonly GstSplitService $gstSplit,
        private readonly HardwareCommerceStatutoryInvoiceGuard $hardwareCommerceInvoice,
    ) {}

    public function evaluateSale(InventorySale $sale): StatutoryMintEligibilityResult
    {
        $errors = [];
        $sale->loadMissing(['lines.product', 'customer', 'branch']);

        if ($sale->status !== InventorySaleStatus::Completed) {
            $errors[] = match ($sale->status) {
                InventorySaleStatus::Cancelled => 'Sale is cancelled.',
                InventorySaleStatus::Returned => 'Sale is returned.',
                default => 'Sale is not completed.',
            };
        }

        if (! StatutoryInvoiceScope::contains($sale->completed_at)) {
            $errors[] = 'Sale is outside the 2026-09-01 invoice scope.';
        }

        if (! $this->numbering->isConfigured()) {
            $errors[] = 'Desk statutory numbering is unset.';
        }

        $issuerError = $this->issuer->errorForSale($sale->branch?->code);
        if ($issuerError !== null) {
            $errors[] = $issuerError;
        } else {
            $sellerError = $this->seller->errorForLocation(
                $this->issuer->requireForProductBranch($sale->branch?->code),
            );
            if ($sellerError !== null) {
                $errors[] = $sellerError;
            }
        }

        $place = is_string($sale->place_of_supply_state) ? trim($sale->place_of_supply_state) : '';
        if ($place === '') {
            $errors[] = self::PLACE_OF_SUPPLY_MISSING;
        }

        $buyerGstin = BuyerGstin::normalize($sale->buyer_gstin);
        if ($sale->buyer_gstin !== null
            && trim((string) $sale->buyer_gstin) !== ''
            && ! BuyerGstin::isValid($buyerGstin)) {
            $errors[] = 'Buyer GSTIN is present but is not a valid 15-character GSTIN.';
        }

        if ($sale->lines->isEmpty()) {
            $errors[] = 'Sale has no invoice lines.';
        }

        foreach ($sale->lines as $line) {
            $hsn = is_string($line->product?->hsn_code) ? trim($line->product->hsn_code) : '';
            if ($hsn === '') {
                $errors[] = 'A line is missing HSN/SAC.';
                break;
            }
        }

        return new StatutoryMintEligibilityResult($errors === [], array_values(array_unique($errors)));
    }

    public function assertSaleCanMint(InventorySale $sale): void
    {
        $result = $this->evaluateSale($sale);
        if ($result->eligible) {
            return;
        }

        throw ValidationException::withMessages([
            'eligibility' => $result->errors,
        ]);
    }

    public function evaluateOrder(CommerceOrder $order): StatutoryMintEligibilityResult
    {
        $errors = [];

        if ($order->payment_status !== 'paid') {
            $errors[] = 'Payment is not paid.';
        }

        if (in_array($order->status, [CommerceOrderStatus::Rejected, CommerceOrderStatus::Failed], true)) {
            $errors[] = 'Order status is not eligible for a statutory invoice.';
        }

        if (! StatutoryInvoiceScope::contains($this->commercialDate($order))) {
            $errors[] = 'Order is outside the 2026-09-01 invoice scope.';
        }

        if (! $this->numbering->isConfigured()) {
            $errors[] = 'Desk statutory numbering is unset.';
        }

        $order->loadMissing(['items', 'hardwareFulfilment']);
        $hardwarePath = $this->hardwareCommerceInvoice->requiresHardwareSerialPath($order);
        $hsnSacs = $order->items->pluck('hsn_sac')->all();
        $issuerError = null;

        if (! $hardwarePath) {
            $issuerError = $this->issuer->errorForCommerceOrder(
                $order->branch_code,
                $order->buyer_gstin,
                $order->billing_state,
                $hsnSacs,
            );
            if ($issuerError !== null) {
                $errors[] = $issuerError;
            } else {
                $sellerError = $this->seller->errorForLocation(
                    $this->issuer->requireForCommerceOrder(
                        $order->branch_code,
                        $order->buyer_gstin,
                        $order->billing_state,
                        $hsnSacs,
                    ),
                );
                if ($sellerError !== null) {
                    $errors[] = $sellerError;
                }
            }
        }

        $place = is_string($order->place_of_supply_state) ? trim($order->place_of_supply_state) : '';
        if ($place === '') {
            $errors[] = GstSplitService::PLACE_OF_SUPPLY_MISSING;
        } elseif (GstStateCodes::codeForName($place) === null) {
            $errors[] = GstSplitService::PLACE_OF_SUPPLY_UNRECOGNISED;
        }

        $buyerGstin = BuyerGstin::normalize($order->buyer_gstin);
        if ($order->buyer_gstin !== null && trim((string) $order->buyer_gstin) !== '' && ! BuyerGstin::isValid($buyerGstin)) {
            $errors[] = 'Buyer GSTIN is present but is not a valid 15-character GSTIN.';
        }

        $order->loadMissing('items');
        if ($order->items->isEmpty()) {
            $errors[] = 'Order has no invoice lines.';
        }

        foreach ($order->items as $line) {
            $description = trim((string) $line->description);
            $hsn = is_string($line->hsn_sac) ? trim($line->hsn_sac) : '';
            if ($description === '' || $hsn === '' || (int) $line->qty < 1
                || $line->unit_price === null || $line->gst_percentage === null
                || $line->taxable_value === null || $line->tax_total === null || $line->line_total === null) {
                $errors[] = 'A line is missing description, HSN/SAC, quantity, price, or statutory amounts.';
                break;
            }
        }

        if ($hardwarePath) {
            $errors = array_merge($errors, $this->hardwareCommerceInvoice->blockingErrors($order));
        } elseif ($issuerError === null && $place !== '' && GstStateCodes::codeForName($place) !== null) {
            $errors = array_merge($errors, $this->serviceGstSplitErrors($order, $place, $hsnSacs));
        }

        return new StatutoryMintEligibilityResult($errors === [], array_values(array_unique($errors)));
    }

    /**
     * @param  list<mixed>  $hsnSacs
     * @return list<string>
     */
    private function serviceGstSplitErrors(CommerceOrder $order, string $place, array $hsnSacs): array
    {
        try {
            $sellerCode = $this->locations->gstStateCode(
                $this->issuer->requireForCommerceOrder(
                    $order->branch_code,
                    $order->buyer_gstin,
                    $order->billing_state,
                    $hsnSacs,
                ),
            );
        } catch (ValidationException $exception) {
            return $this->flattenErrors($exception);
        }

        $errors = [];
        foreach ($order->items as $line) {
            if ($line->gst_percentage === null || $line->taxable_value === null || $line->tax_total === null) {
                continue;
            }

            try {
                $this->gstSplit->splitLine(
                    $sellerCode,
                    $place,
                    (float) $line->gst_percentage,
                    (float) $line->taxable_value,
                    (float) $line->tax_total,
                );
            } catch (ValidationException $exception) {
                $errors = array_merge($errors, $this->flattenErrors($exception));
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function flattenErrors(ValidationException $exception): array
    {
        $flat = [];
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                $flat[] = (string) $message;
            }
        }

        return $flat;
    }

    public function assertOrderCanMint(CommerceOrder $order): void
    {
        $result = $this->evaluateOrder($order);
        if ($result->eligible) {
            return;
        }

        throw ValidationException::withMessages([
            'eligibility' => $result->errors,
        ]);
    }

    public function commercialDate(CommerceOrder $order): ?Carbon
    {
        return $order->ordered_at ?? $order->paid_at ?? $order->received_at;
    }
}
