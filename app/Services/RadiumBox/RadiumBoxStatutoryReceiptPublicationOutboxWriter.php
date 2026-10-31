<?php

namespace App\Services\RadiumBox;

use App\Enums\OutboxEventStatus;
use App\Enums\RadiumBox\RadiumBoxStatutoryReceiptPublicationStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\OutboxEvent;
use App\Models\RadiumBoxStatutoryReceiptPublication;
use App\Models\StatutoryInvoice;
use Illuminate\Support\Facades\DB;

class RadiumBoxStatutoryReceiptPublicationOutboxWriter
{
    public const EVENT_TYPE = 'statutory.radiumbox_receipt.publish';

    public const AGGREGATE_TYPE = 'statutory_invoice';

    public function shouldSchedule(StatutoryInvoice $invoice): bool
    {
        if ($invoice->channel !== StatutoryInvoiceChannel::RadiumBoxCom) {
            return false;
        }

        if ($invoice->source_type !== StatutoryInvoiceSourceType::CommerceOrder
            && $invoice->source_type !== StatutoryInvoiceSourceType::CommerceOrder->value) {
            return false;
        }

        return (bool) config('radiumbox.statutory_receipt_publish.schedule_on_issue', true);
    }

    public function enqueue(StatutoryInvoice $invoice): ?OutboxEvent
    {
        if (! $this->shouldSchedule($invoice)) {
            return null;
        }

        return DB::transaction(function () use ($invoice): OutboxEvent {
            $httpKey = RadiumBoxStatutoryReceiptPublicationPayload::httpIdempotencyKey($invoice);

            RadiumBoxStatutoryReceiptPublication::query()->firstOrCreate(
                ['statutory_invoice_id' => $invoice->id],
                [
                    'http_idempotency_key' => $httpKey,
                    'status' => RadiumBoxStatutoryReceiptPublicationStatus::Pending,
                ],
            );

            $outboxKey = self::outboxIdempotencyKey($invoice->id);
            $existing = OutboxEvent::query()
                ->where('idempotency_key', $outboxKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status === OutboxEventStatus::Completed) {
                    $publication = RadiumBoxStatutoryReceiptPublication::query()
                        ->where('statutory_invoice_id', $invoice->id)
                        ->first();
                    if ($publication !== null && $publication->isTerminalSuccess()) {
                        return $existing;
                    }

                    if ($publication !== null && ! $publication->isTerminalSuccess()) {
                        return $this->reopenOutboxEvent($existing, $invoice->id);
                    }
                }

                if ($existing->status === OutboxEventStatus::Failed) {
                    $existing->update([
                        'event_type' => self::EVENT_TYPE,
                        'aggregate_type' => self::AGGREGATE_TYPE,
                        'aggregate_id' => $invoice->id,
                        'payload' => ['statutory_invoice_id' => $invoice->id],
                        'status' => OutboxEventStatus::Pending,
                        'attempts' => 0,
                        'available_at' => now(),
                        'last_error' => null,
                        'processed_at' => null,
                    ]);

                    return $existing->fresh() ?? $existing;
                }

                if ($existing->status === OutboxEventStatus::Pending
                    || $existing->status === OutboxEventStatus::Processing) {
                    return $existing;
                }
            }

            return OutboxEvent::query()->create([
                'idempotency_key' => $outboxKey,
                'event_type' => self::EVENT_TYPE,
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => $invoice->id,
                'payload' => ['statutory_invoice_id' => $invoice->id],
                'status' => OutboxEventStatus::Pending,
                'attempts' => 0,
                'available_at' => now(),
            ]);
        });
    }

    public static function outboxIdempotencyKey(int $statutoryInvoiceId): string
    {
        return 'statutory-rbox-receipt:invoice:'.$statutoryInvoiceId;
    }

    /**
     * @return array{selected: int, requeueable: int}
     */
    public function previewAwaitingActivationRequeue(?int $limit = null): array
    {
        $selected = 0;
        $requeueable = 0;

        foreach ($this->awaitingActivationPublications($limit) as $publication) {
            $selected++;
            if ($this->resolveRequeueableOutboxEvent($publication) !== null) {
                $requeueable++;
            }
        }

        return [
            'selected' => $selected,
            'requeueable' => $requeueable,
        ];
    }

    /**
     * Re-pend completed outbox rows for publications waiting on Owner activation.
     */
    public function requeueAwaitingActivation(?int $limit = null): int
    {
        $requeued = 0;

        foreach ($this->awaitingActivationPublications($limit) as $publication) {
            $event = $this->resolveRequeueableOutboxEvent($publication);
            if ($event === null) {
                continue;
            }

            $this->reopenOutboxEvent($event, (int) $publication->statutory_invoice_id);
            $requeued++;
        }

        return $requeued;
    }

    /**
     * @return \Illuminate\Support\Collection<int, RadiumBoxStatutoryReceiptPublication>
     */
    private function awaitingActivationPublications(?int $limit = null): \Illuminate\Support\Collection
    {
        $query = RadiumBoxStatutoryReceiptPublication::query()
            ->where('status', RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation)
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    private function resolveRequeueableOutboxEvent(RadiumBoxStatutoryReceiptPublication $publication): ?OutboxEvent
    {
        $outboxKey = self::outboxIdempotencyKey((int) $publication->statutory_invoice_id);
        $event = OutboxEvent::query()->where('idempotency_key', $outboxKey)->first();
        if ($event === null) {
            return null;
        }

        if ($event->status === OutboxEventStatus::Pending
            || $event->status === OutboxEventStatus::Processing) {
            return null;
        }

        if ($event->event_type !== self::EVENT_TYPE) {
            return null;
        }

        return $event;
    }

    private function reopenOutboxEvent(OutboxEvent $event, int $invoiceId): OutboxEvent
    {
        $event->update([
            'event_type' => self::EVENT_TYPE,
            'aggregate_type' => self::AGGREGATE_TYPE,
            'aggregate_id' => $invoiceId,
            'payload' => ['statutory_invoice_id' => $invoiceId],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
            'last_error' => null,
            'processed_at' => null,
        ]);

        return $event->fresh() ?? $event;
    }
}
