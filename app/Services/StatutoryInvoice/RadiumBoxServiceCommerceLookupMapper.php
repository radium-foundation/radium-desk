<?php

namespace App\Services\StatutoryInvoice;

use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\RadiumBox\Exceptions\RadiumBoxOrderNotFoundException;
use App\Services\StatutoryInvoice\Data\RadiumBoxServiceCommerceLookup;
use App\Support\BusinessOrderId;

final class RadiumBoxServiceCommerceLookupMapper
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload, string $expectedOrderId): RadiumBoxServiceCommerceLookup
    {
        if (($payload['status'] ?? null) === 404) {
            throw new RadiumBoxOrderNotFoundException(
                is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'RadiumBox service order not found.',
            );
        }

        if (($payload['status'] ?? null) !== 200) {
            throw new RadiumBoxInvalidResponseException(
                is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'Unexpected RadiumBox service lookup response status.',
            );
        }

        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            throw new RadiumBoxInvalidResponseException('RadiumBox service lookup response is missing data.');
        }

        $commerce = is_array($data['service_commerce'] ?? null) ? $data['service_commerce'] : null;
        if ($commerce === null) {
            throw new RadiumBoxInvalidResponseException('RadiumBox service lookup response is missing service_commerce.');
        }

        $rdOrderId = trim((string) ($commerce['rdorderid'] ?? $expectedOrderId));
        if (! BusinessOrderId::isRadiumBoxService($rdOrderId)) {
            throw new RadiumBoxInvalidResponseException('RadiumBox service lookup returned a non-RB* service identity.');
        }

        $snapshot = is_array($data['snapshot'] ?? null) ? $data['snapshot'] : [];
        $rdOrder = is_array($data['rd_order'] ?? null) ? $data['rd_order'] : [];

        return new RadiumBoxServiceCommerceLookup(
            rdOrderId: $rdOrderId,
            billingState: $this->nullableString($commerce['billing_state'] ?? null),
            placeOfSupplyState: $this->nullableString($commerce['place_of_supply_state'] ?? null),
            billingAddress: $this->nullableString($commerce['billing_address'] ?? null),
            billingAddressStructured: is_array($commerce['billing_address_structured'] ?? null)
                ? $commerce['billing_address_structured']
                : null,
            buyerGstin: $this->nullableString($commerce['gst_no'] ?? $rdOrder['gst_no'] ?? null),
            customerName: $this->nullableString($snapshot['customer_name'] ?? $rdOrder['customer_name'] ?? null),
            customerEmail: $this->nullableString($snapshot['email'] ?? null),
            customerPhone: $this->nullableString($snapshot['phone'] ?? null),
            serialNo: $this->nullableString($commerce['serial_no'] ?? $snapshot['serial_number'] ?? null),
            serviceName: $this->nullableString($commerce['rd_service_name'] ?? $snapshot['rd_service'] ?? null),
            productName: $this->nullableString($commerce['product_name'] ?? $snapshot['product'] ?? null),
            serviceDescription: $this->nullableString($commerce['service_description'] ?? null),
            catalogHsnSac: $this->nullableString($commerce['catalog_hsn_sac'] ?? null),
            taxableValue: $this->nullableFloat($commerce['taxable_value'] ?? null),
            taxTotal: $this->nullableFloat($commerce['tax_total'] ?? null),
            lineTotal: $this->nullableFloat($commerce['line_total'] ?? null),
            gstPercentage: $this->nullableFloat($commerce['gst_percentage'] ?? null),
            orderedAt: $this->nullableString($commerce['ordered_at'] ?? $snapshot['order_date'] ?? null),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }
}
