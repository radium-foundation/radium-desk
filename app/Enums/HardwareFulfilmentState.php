<?php

namespace App\Enums;

enum HardwareFulfilmentState: string
{
    case Paid = 'paid';
    case Ingested = 'ingested';
    case ReadyForFulfilment = 'ready_for_fulfilment';
    case SerialsAllocated = 'serials_allocated';
    case InvoiceIssued = 'invoice_issued';
    case ShipmentCreated = 'shipment_created';
    case AwbAssigned = 'awb_assigned';
    case Shipped = 'shipped';
    case Synced = 'synced';
    case Failed = 'failed';
    case RetryPending = 'retry_pending';

    /**
     * Owner-locked hardware sequence. SERIALS_ALLOCATED precedes INVOICE_ISSUED.
     *
     * @return list<self>
     */
    public static function happyPath(): array
    {
        return [
            self::Paid,
            self::Ingested,
            self::ReadyForFulfilment,
            self::SerialsAllocated,
            self::InvoiceIssued,
            self::ShipmentCreated,
            self::AwbAssigned,
            self::Shipped,
            self::Synced,
        ];
    }
}
