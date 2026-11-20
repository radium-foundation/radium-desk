<?php

namespace App\Services\Shipping;

use App\Models\HardwareFulfilment;
use App\Models\Shipment;
use App\Services\Shipping\Data\ShiprocketCreateOrderRequest;

final class ShiprocketApiAuditContext
{
    public function __construct(
        public readonly ?int $shipmentId = null,
        public readonly ?int $hardwareFulfilmentId = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $merchantReference = null,
        public readonly ?string $externalOrderId = null,
        public readonly ?string $externalShipmentId = null,
        public readonly ?string $awb = null,
    ) {}

    public static function fromShipment(Shipment $shipment): self
    {
        return new self(
            shipmentId: (int) $shipment->id,
            hardwareFulfilmentId: $shipment->hardware_fulfilment_id !== null ? (int) $shipment->hardware_fulfilment_id : null,
            correlationId: $shipment->correlation_id !== null ? (string) $shipment->correlation_id : null,
            merchantReference: $shipment->shipment_no !== null ? (string) $shipment->shipment_no : null,
            externalOrderId: $shipment->external_order_id !== null ? (string) $shipment->external_order_id : null,
            externalShipmentId: $shipment->external_shipment_id !== null ? (string) $shipment->external_shipment_id : null,
            awb: $shipment->awb !== null ? (string) $shipment->awb : null,
        );
    }

    public static function fromCreateRequest(ShiprocketCreateOrderRequest $request, Shipment $shipment): self
    {
        return new self(
            shipmentId: (int) $shipment->id,
            hardwareFulfilmentId: $shipment->hardware_fulfilment_id !== null ? (int) $shipment->hardware_fulfilment_id : null,
            correlationId: $request->correlationId,
            merchantReference: $request->merchantOrderId,
            externalOrderId: $shipment->external_order_id !== null ? (string) $shipment->external_order_id : null,
            externalShipmentId: $shipment->external_shipment_id !== null ? (string) $shipment->external_shipment_id : null,
            awb: $shipment->awb !== null ? (string) $shipment->awb : null,
        );
    }

    public static function fromHardwareFulfilment(
        HardwareFulfilment $fulfilment,
        ?Shipment $shipment = null,
        ?string $merchantReference = null,
    ): self {
        if ($shipment !== null) {
            $context = self::fromShipment($shipment);
            if ($merchantReference !== null && $merchantReference !== '') {
                return new self(
                    shipmentId: $context->shipmentId,
                    hardwareFulfilmentId: $context->hardwareFulfilmentId,
                    correlationId: $context->correlationId,
                    merchantReference: $merchantReference,
                    externalOrderId: $context->externalOrderId,
                    externalShipmentId: $context->externalShipmentId,
                    awb: $context->awb,
                );
            }

            return $context;
        }

        return new self(
            hardwareFulfilmentId: (int) $fulfilment->id,
            merchantReference: $merchantReference,
        );
    }
}
