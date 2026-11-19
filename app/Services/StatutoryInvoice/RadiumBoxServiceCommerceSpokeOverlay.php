<?php

namespace App\Services\StatutoryInvoice;

use App\Support\BusinessOrderId;

/**
 * Reconstructs authoritative RB* service commerce when the spoke omits data.service_commerce.
 *
 * Mirrors radiumbox.com DeskOrderLookupService::serviceCommerce() using fields present on
 * data.rd_order / data.snapshot. Fails closed when statutory amounts or billing state are absent.
 */
final class RadiumBoxServiceCommerceSpokeOverlay
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function synthesize(array $data, string $expectedOrderId): ?array
    {
        $orderId = trim($expectedOrderId);
        if (! BusinessOrderId::isRadiumBoxService($orderId)) {
            return null;
        }

        $rdOrder = is_array($data['rd_order'] ?? null) ? $data['rd_order'] : null;
        if ($rdOrder === null) {
            return null;
        }

        $snapshot = is_array($data['snapshot'] ?? null) ? $data['snapshot'] : [];
        $customerAddress = $this->nullableString($snapshot['address'] ?? null);
        $pincode = $this->nullableString($snapshot['pincode'] ?? null);

        $billingState = RadiumBoxLegacyBillingStateResolver::resolve(
            $this->nullableString($rdOrder['state'] ?? $rdOrder['billing_state'] ?? null),
        );
        if ($billingState === null) {
            return null;
        }

        $district = $this->nullableString($rdOrder['district'] ?? null);
        $baseTaxable = $this->decimalAmount($rdOrder['base_taxable_value'] ?? $rdOrder['amount'] ?? null);
        $durationPrice = max(0.0, $this->decimalAmount($rdOrder['duration_price'] ?? null) ?? 0.0);
        $taxable = $this->decimalAmount($rdOrder['taxable_value'] ?? null);
        if ($taxable === null && $baseTaxable !== null) {
            $taxable = round($baseTaxable + max(0.0, $durationPrice), 2);
        }

        $taxTotal = $this->decimalAmount($rdOrder['tax_amount'] ?? $rdOrder['tax_total'] ?? null);
        $lineTotal = $this->decimalAmount($rdOrder['paid_amount'] ?? $rdOrder['line_total'] ?? null);
        $gstRate = $this->gstPercentageFromAmounts($taxable, $taxTotal);

        if ($taxable === null || $taxTotal === null || $lineTotal === null || $gstRate === null) {
            return null;
        }

        if (round($taxable + $taxTotal, 2) !== round($lineTotal, 2)) {
            return null;
        }

        $serial = $this->nullableString($rdOrder['serial_no'] ?? $snapshot['serial_number'] ?? null);
        $serviceName = $this->nullableString(
            $rdOrder['rd_service_name'] ?? $snapshot['rd_service'] ?? null,
        );
        $serviceDescription = $this->serviceDescription($serial, $serviceName);
        $catalogSac = $this->nullableString($rdOrder['catalog_hsn_sac'] ?? '998314');

        if ($serviceDescription === null || $catalogSac === null) {
            return null;
        }

        $structured = $this->structuredBillingAddress($customerAddress, $district, $billingState, $pincode);

        return [
            'rdorderid' => $this->nullableString($rdOrder['rdorderid'] ?? null) ?? $orderId,
            'billing_state' => $billingState,
            'place_of_supply_state' => $billingState,
            'district' => $district,
            'billing_address' => $this->flattenBillingAddress($structured, $customerAddress),
            'billing_address_structured' => $structured,
            'gst_no' => $this->nullableString($rdOrder['gst_no'] ?? $snapshot['gst_number'] ?? null),
            'amount' => $baseTaxable,
            'base_taxable_value' => $baseTaxable,
            'duration' => $this->nullableString($rdOrder['duration'] ?? null),
            'duration_price' => $durationPrice > 0 ? $durationPrice : null,
            'taxable_value' => $taxable,
            'tax_total' => $taxTotal,
            'line_total' => $lineTotal,
            'gst_percentage' => $gstRate,
            'catalog_hsn_sac' => $catalogSac,
            'service_description' => $serviceDescription,
            'ordered_at' => $this->nullableString(
                $rdOrder['created_at'] ?? $snapshot['order_date'] ?? null,
            ),
            'product_name' => $this->nullableString($rdOrder['product_name'] ?? $snapshot['product'] ?? null),
            'rd_service_name' => $serviceName,
            'serial_no' => $serial,
        ];
    }

    private function serviceDescription(?string $serial, ?string $serviceName): ?string
    {
        if ($serial === null || $serviceName === null) {
            return null;
        }

        return 'Information Technology (IT) Consulting & Support Services (SAC - 998313) - (Sr. No. '
            .$serial.') - '.$serviceName;
    }

    /**
     * @return array<string, string>|null
     */
    private function structuredBillingAddress(
        ?string $line1,
        ?string $city,
        ?string $state,
        ?string $pincode,
    ): ?array {
        $out = [];
        if ($line1 !== null) {
            $out['line1'] = $line1;
        }
        if ($city !== null) {
            $out['city'] = $city;
        }
        if ($state !== null) {
            $out['state'] = $state;
        }
        if ($pincode !== null) {
            $out['pincode'] = $pincode;
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, string>|null  $structured
     */
    private function flattenBillingAddress(?array $structured, ?string $fallbackLine): ?string
    {
        if (! is_array($structured)) {
            return $fallbackLine;
        }

        $parts = [];
        foreach (['line1', 'city', 'state', 'pincode'] as $key) {
            $value = is_string($structured[$key] ?? null) ? trim($structured[$key]) : '';
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return $fallbackLine;
    }

    private function decimalAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function gstPercentageFromAmounts(?float $taxable, ?float $taxTotal): ?float
    {
        if ($taxable === null || $taxTotal === null || $taxable <= 0 || $taxTotal < 0) {
            return null;
        }

        return round($taxTotal / $taxable * 100, 2);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}