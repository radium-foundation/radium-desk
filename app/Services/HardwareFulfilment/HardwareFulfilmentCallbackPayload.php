<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\StatutoryInvoice;

final class HardwareFulfilmentCallbackPayload
{
    public const CONTRACT_VERSION = 'v1';

    /**
     * @return array<string, mixed>
     */
    public static function build(
        HardwareFulfilment $fulfilment,
        HardwareFulfilmentEvent $event,
        string $eventId,
    ): array {
        $state = $event->to_state;
        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'event_id' => $eventId,
            'event_type' => self::eventType($state),
            'channel' => $fulfilment->channel instanceof StatutoryInvoiceChannel
                ? $fulfilment->channel->value
                : (string) $fulfilment->channel,
            'source_type' => (string) $fulfilment->source_type,
            'source_id' => (string) $fulfilment->source_id,
            'identity' => (string) $fulfilment->idempotency_key,
            'commerce_order_id' => (int) $fulfilment->commerce_order_id,
            'hardware_fulfilment_id' => (int) $fulfilment->id,
            'state' => $state->value,
            'sequence' => (int) $event->id,
            'occurred_at' => ($event->created_at ?? now())->toIso8601String(),
        ];

        $invoice = self::invoiceBlock($fulfilment, $state);
        if ($invoice !== null) {
            $payload['invoice'] = $invoice;
        }

        $serials = self::serials($fulfilment, $state);
        if ($serials !== null) {
            $payload['serials'] = $serials;
        }

        $shipment = self::shipmentBlock($fulfilment, $state);
        if ($shipment !== null) {
            $payload['shipment'] = $shipment;
        }

        return $payload;
    }

    public static function eventType(HardwareFulfilmentState $state): string
    {
        return 'hardware.fulfilment.'.$state->value;
    }

    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{number: string, issued_at: string}|null
     */
    private static function invoiceBlock(HardwareFulfilment $fulfilment, HardwareFulfilmentState $state): ?array
    {
        if ($state->rank() < HardwareFulfilmentState::InvoiceIssued->rank()) {
            return null;
        }

        if ($fulfilment->statutory_invoice_id === null) {
            return null;
        }

        $invoice = StatutoryInvoice::query()->find($fulfilment->statutory_invoice_id);
        if ($invoice === null || ! filled($invoice->invoice_number)) {
            return null;
        }

        return [
            'number' => (string) $invoice->invoice_number,
            'issued_at' => optional($invoice->issued_at)->toIso8601String()
                ?? optional($fulfilment->invoice_issued_at)->toIso8601String()
                ?? now()->toIso8601String(),
        ];
    }

    /**
     * @return list<string>|null
     */
    private static function serials(HardwareFulfilment $fulfilment, HardwareFulfilmentState $state): ?array
    {
        if ($state->rank() < HardwareFulfilmentState::SerialsAllocated->rank()) {
            return null;
        }

        return $fulfilment->serials()
            ->where('status', HardwareFulfilmentSerialStatus::Allocated->value)
            ->whereNotNull('serial_number')
            ->orderBy('line_no')
            ->orderBy('position')
            ->pluck('serial_number')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>|null
     */
    private static function shipmentBlock(HardwareFulfilment $fulfilment, HardwareFulfilmentState $state): ?array
    {
        if ($state->rank() < HardwareFulfilmentState::ShipmentCreated->rank()) {
            return null;
        }

        $block = [];
        if (filled($fulfilment->shipment_no)) {
            $block['shipment_no'] = (string) $fulfilment->shipment_no;
        }
        if (filled($fulfilment->provider_shipment_id)) {
            $block['provider_shipment_id'] = (string) $fulfilment->provider_shipment_id;
        }
        if (filled($fulfilment->awb)) {
            $block['awb'] = (string) $fulfilment->awb;
        }

        return $block === [] ? null : $block;
    }
}
