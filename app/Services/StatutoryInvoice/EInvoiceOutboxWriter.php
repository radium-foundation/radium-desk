<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;

class EInvoiceOutboxWriter
{
    public const EVENT_TYPE = 'statutory.invoice.einvoice';

    public const AGGREGATE_TYPE = 'statutory_invoice';

    public function write(StatutoryInvoice $invoice): OutboxEvent
    {
        return OutboxEvent::query()->firstOrCreate(
            ['idempotency_key' => self::idempotencyKeyForInvoice($invoice)],
            [
                'event_type' => self::EVENT_TYPE,
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => $invoice->id,
                'payload' => [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                ],
                'status' => OutboxEventStatus::Pending,
                'attempts' => 0,
                'available_at' => now(),
            ],
        );
    }

    public static function idempotencyKeyForInvoice(StatutoryInvoice $invoice): string
    {
        return 'statutory-irn:'.$invoice->id;
    }
}
