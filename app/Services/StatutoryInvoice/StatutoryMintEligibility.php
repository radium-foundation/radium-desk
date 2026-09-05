<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\InventorySaleStatus;
use App\Models\CommerceOrder;
use App\Models\InventorySale;
use App\Services\StatutoryInvoice\Data\StatutoryMintEligibilityResult;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class StatutoryMintEligibility
{
    public function __construct(
        private readonly StatutoryInvoiceNumberingService $numbering,
    ) {}

    public function evaluateSale(InventorySale $sale): StatutoryMintEligibilityResult
    {
        $errors = [];
        $sale->loadMissing(['lines.product', 'customer']);

        if ($sale->status !== InventorySaleStatus::Completed) {
            $errors[] = match ($sale->status) {
                InventorySaleStatus::Cancelled => 'Sale is cancelled.',
                InventorySaleStatus::Returned => 'Sale is returned.',
                default => 'Sale is not completed.',
            };
        }

        if (! $this->numbering->isConfigured()) {
            $errors[] = 'Desk statutory numbering is unset.';
        }

        if ($this->configString('gstin_scope') === null) {
            $errors[] = 'Desk seller GSTIN is unset.';
        }

        if ($this->configString('legal_name') === null) {
            $errors[] = 'Desk seller legal name is unset.';
        }

        $place = is_string($sale->place_of_supply_state) ? trim($sale->place_of_supply_state) : '';
        if ($place === '') {
            $errors[] = 'Place of supply is missing.';
        }

        $buyerGstin = BuyerGstin::normalize($sale->buyer_gstin ?? $sale->customer?->gstin);
        if (($sale->buyer_gstin ?? $sale->customer?->gstin) !== null
            && trim((string) ($sale->buyer_gstin ?? $sale->customer?->gstin)) !== ''
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

        if ($this->configString('gstin_scope') === null) {
            $errors[] = 'Desk seller GSTIN is unset.';
        }

        if ($this->configString('legal_name') === null) {
            $errors[] = 'Desk seller legal name is unset.';
        }

        $place = is_string($order->place_of_supply_state) ? trim($order->place_of_supply_state) : '';
        if ($place === '') {
            $errors[] = 'Place of supply is missing.';
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

        return new StatutoryMintEligibilityResult($errors === [], array_values(array_unique($errors)));
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

    private function configString(string $key): ?string
    {
        $value = config('statutory_invoices.'.$key);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
