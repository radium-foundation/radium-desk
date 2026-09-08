<?php

namespace App\Enums;

enum HardwareFulfilmentOperationalStage: string
{
    case AwaitingFulfilment = 'awaiting_fulfilment';
    case AwaitingSerial = 'awaiting_serial';
    case AwaitingInvoice = 'awaiting_invoice';
    case ReadyForShipment = 'ready_for_shipment';
    case ShipmentCreated = 'shipment_created';
    case AwbPending = 'awb_pending';
    case LabelPackingPending = 'label_packing_pending';
    case PickupManifestPending = 'pickup_manifest_pending';
    case ReadyForPickup = 'ready_for_pickup';
    case Completed = 'completed';
    case BlockedReview = 'blocked_review';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingFulfilment => 'Awaiting Fulfilment',
            self::AwaitingSerial => 'Awaiting Serial',
            self::AwaitingInvoice => 'Awaiting Invoice',
            self::ReadyForShipment => 'Ready for Shipment',
            self::ShipmentCreated => 'Shipment Created',
            self::AwbPending => 'AWB Pending',
            self::LabelPackingPending => 'Label Pending',
            self::PickupManifestPending => 'Pickup',
            self::ReadyForPickup => 'Ready for Pickup',
            self::Completed => 'Completed',
            self::BlockedReview => 'Blocked',
        };
    }
}
