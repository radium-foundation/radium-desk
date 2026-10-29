<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\OutboxEventStatus;
use App\Models\EInvoiceRecord;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use Illuminate\Support\Facades\DB;

class EInvoiceOutboxWriter
{
    public const EVENT_TYPE = 'statutory.invoice.einvoice';

    public const AGGREGATE_TYPE = 'statutory_invoice';

    public function __construct(
        private readonly EInvoiceOutboxRetryGate $retryGate,
    ) {}

    public function write(StatutoryInvoice $invoice): OutboxEvent
    {
        return DB::transaction(function () use ($invoice): OutboxEvent {
            $key = self::idempotencyKeyForInvoice($invoice);
            $event = OutboxEvent::query()
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                return OutboxEvent::query()->create($this->newEventAttributes($invoice));
            }

            $record = EInvoiceRecord::query()
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();

            $invoice->loadMissing('items');

            if ($this->retryGate->mayReopenTerminalOutbox($invoice, $event, $record)) {
                $event->update([
                    'event_type' => self::EVENT_TYPE,
                    'aggregate_type' => self::AGGREGATE_TYPE,
                    'aggregate_id' => $invoice->id,
                    'payload' => [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                    ],
                    'status' => OutboxEventStatus::Pending,
                    'available_at' => now(),
                    'last_error' => null,
                ]);

                return $event->fresh() ?? $event;
            }

            return $event;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function newEventAttributes(StatutoryInvoice $invoice): array
    {
        return [
            'idempotency_key' => self::idempotencyKeyForInvoice($invoice),
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
        ];
    }

    public static function idempotencyKeyForInvoice(StatutoryInvoice $invoice): string
    {
        return 'statutory-irn:'.$invoice->id;
    }
}
