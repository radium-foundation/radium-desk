<?php

namespace App\Services\HardwareFulfilment;

use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\StatutoryInvoice;
use App\Support\Inventory\InventorySerialNumber;
use Illuminate\Validation\ValidationException;

class HardwareShipmentEligibility
{
    public function __construct(
        private readonly HardwareFulfilmentWorkflowService $workflow,
        private readonly HardwarePickupResolver $pickups,
    ) {}

    /**
     * @return array{
     *     invoice: StatutoryInvoice,
     *     serials: list<string>,
     *     branch: InventoryBranch,
     *     pickup: string,
     *     shipping: array<string, string>,
     *     parcel: array{weight: float, length: float, breadth: float, height: float}
     * }
     */
    public function require(HardwareFulfilment $fulfilment): array
    {
        if (HardwareFulfilmentEligibility::isFrozenSourceId((string) $fulfilment->source_id)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot be shipped.',
            ]);
        }

        $this->workflow->assertCanCreateShipment($fulfilment);

        $order = $fulfilment->commerceOrder;
        if ($order === null) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Hardware fulfilment is missing its commerce order.',
            ]);
        }

        $invoice = $this->requireInvoice($fulfilment, $order);
        $serials = $this->requireSerials($fulfilment, $order);
        $branch = $this->requireBranch($fulfilment);
        $pickup = $this->pickups->requireForBranch($branch);
        $shipping = $this->requireShippingAddress($order);
        $parcel = $this->requireParcel($order);

        return [
            'invoice' => $invoice,
            'serials' => $serials,
            'branch' => $branch,
            'pickup' => $pickup,
            'shipping' => $shipping,
            'parcel' => $parcel,
        ];
    }

    public function assertProviderConfigured(): void
    {
        if (! (bool) config('shipping.enabled')) {
            throw ValidationException::withMessages([
                'shipping' => 'Shiprocket is disabled. No provider call was made.',
            ]);
        }

        $provider = (string) config('shipping.provider', 'none');
        if ($provider === '' || $provider === 'none') {
            throw ValidationException::withMessages([
                'shipping' => 'Shiprocket provider is not configured. No provider call was made.',
            ]);
        }
    }

    private function requireInvoice(HardwareFulfilment $fulfilment, CommerceOrder $order): StatutoryInvoice
    {
        $invoiceId = $fulfilment->statutory_invoice_id ?? $order->statutory_invoice_id;
        if ($invoiceId === null) {
            throw ValidationException::withMessages([
                'invoice' => 'Hardware shipment requires a linked statutory invoice.',
            ]);
        }

        $invoice = StatutoryInvoice::query()->find($invoiceId);
        if ($invoice === null || ! filled($invoice->invoice_number)) {
            throw ValidationException::withMessages([
                'invoice' => 'Hardware shipment requires a statutory invoice number. P5 does not mint invoices.',
            ]);
        }

        return $invoice;
    }

    /**
     * @return list<string>
     */
    private function requireSerials(HardwareFulfilment $fulfilment, CommerceOrder $order): array
    {
        $serials = array_values(array_map(
            static fn (string $serial): string => InventorySerialNumber::normalize($serial),
            $this->workflow->allocatedSerialNumbers($fulfilment),
        ));

        if ($serials === []) {
            throw ValidationException::withMessages([
                'serials' => 'Hardware shipment requires persisted allocated serials.',
            ]);
        }

        $required = 0;
        foreach ($order->items as $item) {
            if (HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                $required += (int) $item->qty;
            }
        }

        if (count($serials) !== $required) {
            throw ValidationException::withMessages([
                'serials' => sprintf(
                    'Allocated serial count %d does not match physical quantity %d.',
                    count($serials),
                    $required,
                ),
            ]);
        }

        $locked = $fulfilment->metadata['invoice_serials'] ?? null;
        if (is_array($locked) && $locked !== []) {
            $normalizedLocked = array_values(array_map(
                static fn (mixed $serial): string => InventorySerialNumber::normalize((string) $serial),
                $locked,
            ));
            $a = $serials;
            $b = $normalizedLocked;
            sort($a, SORT_STRING);
            sort($b, SORT_STRING);
            if ($a !== $b) {
                throw ValidationException::withMessages([
                    'serials' => 'Shipment serials differ from the invoice-locked allocation.',
                ]);
            }
        }

        return $serials;
    }

    private function requireBranch(HardwareFulfilment $fulfilment): InventoryBranch
    {
        if ($fulfilment->fulfilment_branch_id === null) {
            throw ValidationException::withMessages([
                'branch' => 'Hardware shipment requires a fulfilment branch. Customer state cannot substitute.',
            ]);
        }

        $branch = InventoryBranch::query()->find($fulfilment->fulfilment_branch_id);
        if ($branch === null || ! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch' => 'Hardware fulfilment branch is missing or inactive.',
            ]);
        }

        return $branch;
    }

    /**
     * @return array<string, string>
     */
    private function requireShippingAddress(CommerceOrder $order): array
    {
        $structured = $order->shipping_address_structured;
        if (! is_array($structured)) {
            throw ValidationException::withMessages([
                'address' => 'Hardware shipment requires a structured shipping address. Billing address is not substituted.',
            ]);
        }

        $required = ['line1', 'city', 'state', 'pincode', 'country'];
        $out = [];
        foreach ($required as $key) {
            $value = trim((string) ($structured[$key] ?? ''));
            if ($value === '') {
                throw ValidationException::withMessages([
                    'address' => sprintf('Shipping address is missing %s. Billing address is not substituted.', $key),
                ]);
            }
            $out[$key] = $value;
        }

        $out['line2'] = trim((string) ($structured['line2'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) $order->customer_phone) ?? '';
        if (strlen($phone) < 10) {
            throw ValidationException::withMessages([
                'address' => 'Hardware shipment requires a customer phone of at least 10 digits.',
            ]);
        }
        $out['phone'] = $phone;

        $email = trim((string) $order->customer_email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'address' => 'Hardware shipment requires a customer email. One is not invented.',
            ]);
        }
        $out['email'] = $email;
        $out['name'] = trim((string) $order->customer_name);
        if ($out['name'] === '') {
            throw ValidationException::withMessages([
                'address' => 'Hardware shipment requires a customer name.',
            ]);
        }

        return $out;
    }

    /**
     * @return array{weight: float, length: float, breadth: float, height: float}
     */
    private function requireParcel(CommerceOrder $order): array
    {
        $parcel = $order->parcel;
        if (! is_array($parcel)) {
            throw ValidationException::withMessages([
                'parcel' => 'Hardware shipment requires persisted parcel dimensions. Defaults are not invented.',
            ]);
        }

        $weight = $this->positiveNumber($parcel['weight'] ?? null, 'weight');
        $length = $this->positiveNumber($parcel['length'] ?? null, 'length');
        $height = $this->positiveNumber($parcel['height'] ?? null, 'height');
        $breadth = $this->positiveNumber($parcel['breadth'] ?? $parcel['width'] ?? null, 'breadth');

        return [
            'weight' => $weight,
            'length' => $length,
            'breadth' => $breadth,
            'height' => $height,
        ];
    }

    private function positiveNumber(mixed $value, string $field): float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages([
                'parcel' => sprintf('Parcel %s is missing. Defaults are not invented.', $field),
            ]);
        }

        $number = (float) $value;
        if ($number <= 0) {
            throw ValidationException::withMessages([
                'parcel' => sprintf('Parcel %s must be greater than zero.', $field),
            ]);
        }

        return $number;
    }
}
