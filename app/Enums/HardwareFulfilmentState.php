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

    public function rank(): int
    {
        return match ($this) {
            self::Paid => 0,
            self::Ingested => 1,
            self::ReadyForFulfilment => 2,
            self::SerialsAllocated => 3,
            self::InvoiceIssued => 4,
            self::ShipmentCreated => 5,
            self::AwbAssigned => 6,
            self::Shipped => 7,
            self::Synced => 8,
            self::Failed, self::RetryPending => -1,
        };
    }

    /**
     * Forward-only happy path. Serial allocation cannot be skipped.
     * Failed / retry_pending never regress a durable happy-path state by themselves.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Paid => [self::Ingested, self::Failed, self::RetryPending],
            self::Ingested => [self::ReadyForFulfilment, self::Failed, self::RetryPending],
            self::ReadyForFulfilment => [self::SerialsAllocated, self::Failed, self::RetryPending],
            self::SerialsAllocated => [self::InvoiceIssued, self::Failed, self::RetryPending],
            self::InvoiceIssued => [self::ShipmentCreated, self::Failed, self::RetryPending],
            self::ShipmentCreated => [self::AwbAssigned, self::Failed, self::RetryPending],
            self::AwbAssigned => [self::Shipped, self::Failed, self::RetryPending],
            self::Shipped => [self::Synced, self::Failed, self::RetryPending],
            self::Synced => [self::Synced, self::Failed, self::RetryPending],
            self::Failed => [self::RetryPending],
            self::RetryPending => [self::Failed],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next && $this === self::Synced) {
            return true;
        }

        return in_array($next, $this->allowedTransitions(), true);
    }

    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Ingested => 'ingested_at',
            self::ReadyForFulfilment => 'ready_at',
            self::SerialsAllocated => 'serials_allocated_at',
            self::InvoiceIssued => 'invoice_issued_at',
            self::ShipmentCreated => 'shipment_created_at',
            self::AwbAssigned => 'awb_assigned_at',
            self::Shipped => 'shipped_at',
            self::Synced => 'synced_at',
            default => null,
        };
    }
}
