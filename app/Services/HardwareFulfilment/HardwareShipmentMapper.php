<?php

namespace App\Services\HardwareFulfilment;

use App\Models\CommerceOrder;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Services\Shipping\Data\ShiprocketCreateOrderRequest;

class HardwareShipmentMapper
{
    public function __construct(
        private readonly HardwareShipmentCollectionModeResolver $collectionModes,
    ) {}

    /**
     * @param  list<string>  $serials
     * @param  array<string, string>  $shipping
     * @param  array{weight: float, length: float, breadth: float, height: float}  $parcel
     */
    public function map(
        Shipment $shipment,
        CommerceOrder $order,
        StatutoryInvoice $invoice,
        array $serials,
        array $shipping,
        array $parcel,
        string $pickup,
    ): ShiprocketCreateOrderRequest {
        $order->loadMissing('items');
        $items = [];
        $subTotal = 0.0;

        foreach ($order->items as $item) {
            if (! HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                continue;
            }

            $qty = max(1, (int) $item->qty);
            $unit = round(((float) $item->line_total) / $qty, 2);
            $subTotal += (float) $item->line_total;
            $items[] = [
                'name' => trim((string) $item->description).' ['.$invoice->invoice_number.']',
                'sku' => (string) ($item->sku ?: $item->catalog_sku ?: 'HW'),
                'units' => $qty,
                'selling_price' => $unit,
                'tax' => $item->gst_percentage !== null ? (float) $item->gst_percentage : null,
            ];
        }

        $channelId = trim((string) config('shipping.channel_id', ''));

        return new ShiprocketCreateOrderRequest(
            merchantOrderId: $shipment->shipment_no,
            localShipmentId: (int) $shipment->id,
            correlationId: (string) $shipment->correlation_id,
            items: $items,
            orderDate: optional($order->paid_at ?? $order->ordered_at ?? $order->received_at)?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i'),
            pickupLocation: $pickup,
            billingCustomerName: $shipping['name'],
            billingAddress: $shipping['line1'],
            billingCity: $shipping['city'],
            billingPincode: $shipping['pincode'],
            billingState: $shipping['state'],
            billingCountry: $shipping['country'],
            billingEmail: $shipping['email'],
            billingPhone: $shipping['phone'],
            shippingIsBilling: true,
            paymentMethod: $this->collectionModes->current()->providerPaymentMethod(),
            subTotal: (string) round($subTotal, 2),
            length: $parcel['length'],
            breadth: $parcel['breadth'],
            height: $parcel['height'],
            weight: $parcel['weight'],
            billingAddress2: $shipping['line2'] !== '' ? $shipping['line2'] : null,
            channelId: $channelId !== '' ? $channelId : null,
            customerGstin: $order->buyer_gstin,
        );
    }
}
