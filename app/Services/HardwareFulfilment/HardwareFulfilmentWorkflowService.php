<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HardwareFulfilmentWorkflowService
{
    public function __construct(
        private readonly HardwareFulfilmentCallbackOutboxWriter $callbackOutbox,
    ) {}

    public function transition(
        HardwareFulfilment $fulfilment,
        HardwareFulfilmentState $to,
        string $actorType = 'system',
        ?int $actorId = null,
        array $payload = [],
    ): HardwareFulfilment {
        if (HardwareFulfilmentEligibility::isFrozenSourceId((string) $fulfilment->source_id)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot change fulfilment state.',
            ]);
        }

        if (HardwareFulfilmentEligibility::isHoldSourceId((string) $fulfilment->source_id)
            || HardwareFulfilmentEligibility::metadataShowsHold($fulfilment->metadata)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Owner-HOLD hardware orders cannot change fulfilment state.',
            ]);
        }

        return DB::transaction(function () use ($fulfilment, $to, $actorType, $actorId, $payload): HardwareFulfilment {
            $locked = HardwareFulfilment::query()
                ->whereKey($fulfilment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->state === $to) {
                return $locked;
            }

            if (! $locked->state->canTransitionTo($to)) {
                $message = sprintf(
                    'Hardware fulfilment cannot move from %s to %s.',
                    $locked->state->value,
                    $to->value,
                );
                if ($to === HardwareFulfilmentState::InvoiceIssued) {
                    $message = 'SERIALS_ALLOCATED must precede INVOICE_ISSUED.';
                }

                throw ValidationException::withMessages([
                    'state' => $message,
                ]);
            }

            $this->assertSerialFirst($locked->state, $to);

            $from = $locked->state;
            $updates = ['state' => $to];
            $timestamp = $to->timestampColumn();
            if ($timestamp !== null && $locked->{$timestamp} === null) {
                $updates[$timestamp] = now();
            }

            $locked->forceFill($updates)->save();

            $event = HardwareFulfilmentEvent::query()->create([
                'hardware_fulfilment_id' => $locked->id,
                'from_state' => $from,
                'to_state' => $to,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'payload' => $payload === [] ? ['reason' => 'workflow_transition'] : $payload,
                'created_at' => now(),
            ]);

            $fresh = $locked->fresh() ?? $locked;
            $this->callbackOutbox->enqueue($fresh, $event);

            return $fresh;
        });
    }

    public function markReady(
        HardwareFulfilment $fulfilment,
        string $actorType = 'system',
        ?int $actorId = null,
    ): HardwareFulfilment {
        $locked = $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
        $order = $locked->commerceOrder;
        if ($order === null) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Hardware fulfilment is missing its commerce order.',
            ]);
        }

        HardwareFulfilmentEligibility::assertIsolatedTarget($locked, $order);

        if ($locked->state === HardwareFulfilmentState::ReadyForFulfilment) {
            return $locked;
        }

        if ($locked->state !== HardwareFulfilmentState::Ingested) {
            throw ValidationException::withMessages([
                'state' => 'READY_FOR_FULFILMENT requires INGESTED.',
            ]);
        }

        return $this->transition(
            $locked,
            HardwareFulfilmentState::ReadyForFulfilment,
            actorType: $actorType,
            actorId: $actorId,
            payload: ['reason' => 'isolated_ready_for_fulfilment'],
        );
    }

    public function markShipped(
        HardwareFulfilment $fulfilment,
        string $actorType = 'system',
        ?int $actorId = null,
    ): HardwareFulfilment {
        $locked = $fulfilment->fresh(['shipment']) ?? $fulfilment;

        if ($locked->state === HardwareFulfilmentState::Shipped
            || $locked->state === HardwareFulfilmentState::Synced) {
            return $locked;
        }

        if ($locked->state !== HardwareFulfilmentState::AwbAssigned) {
            throw ValidationException::withMessages([
                'state' => 'SHIPPED requires AWB_ASSIGNED with verified provider AWB evidence.',
            ]);
        }

        $awb = trim((string) $locked->awb);
        $providerAwb = trim((string) $locked->provider_awb);
        $shipmentAwb = trim((string) ($locked->shipment?->awb ?? ''));

        if ($awb === '' || $providerAwb === '' || $shipmentAwb === '') {
            throw ValidationException::withMessages([
                'shipping' => 'SHIPPED requires verified provider AWB evidence. A shipment request is not enough.',
            ]);
        }

        if ($awb !== $providerAwb || $awb !== $shipmentAwb) {
            throw ValidationException::withMessages([
                'shipping' => 'SHIPPED requires the fulfilment AWB to match the provider shipment AWB.',
            ]);
        }

        return $this->transition(
            $locked,
            HardwareFulfilmentState::Shipped,
            actorType: $actorType,
            actorId: $actorId,
            payload: [
                'reason' => 'isolated_mark_shipped',
                'awb' => $awb,
            ],
        );
    }

    public function markSynced(
        HardwareFulfilment $fulfilment,
        string $actorType = 'system',
        ?int $actorId = null,
        array $payload = [],
    ): HardwareFulfilment {
        $locked = $fulfilment->fresh() ?? $fulfilment;
        if ($locked->state === HardwareFulfilmentState::Synced) {
            return $locked;
        }

        if ($locked->state !== HardwareFulfilmentState::Shipped) {
            throw ValidationException::withMessages([
                'state' => 'SYNCED requires SHIPPED.',
            ]);
        }

        return $this->transition(
            $locked,
            HardwareFulfilmentState::Synced,
            actorType: $actorType,
            actorId: $actorId,
            payload: $payload === [] ? ['reason' => 'isolated_synced'] : $payload,
        );
    }

    public function assertCanAllocateSerials(HardwareFulfilment $fulfilment): void
    {
        if ($fulfilment->state !== HardwareFulfilmentState::ReadyForFulfilment) {
            throw ValidationException::withMessages([
                'serials' => 'Serial allocation requires READY_FOR_FULFILMENT. Payment success is not enough.',
            ]);
        }
    }

    public function assertCanIssueInvoice(HardwareFulfilment $fulfilment): void
    {
        if ($fulfilment->state !== HardwareFulfilmentState::SerialsAllocated) {
            throw ValidationException::withMessages([
                'invoice' => 'Hardware invoice issuance requires SERIALS_ALLOCATED. READY_FOR_FULFILMENT cannot skip serials.',
            ]);
        }
    }

    public function assertCanCreateShipment(HardwareFulfilment $fulfilment): void
    {
        if ($fulfilment->state !== HardwareFulfilmentState::InvoiceIssued) {
            throw ValidationException::withMessages([
                'shipment' => 'Shipment requires INVOICE_ISSUED after SERIALS_ALLOCATED.',
            ]);
        }
    }

    /**
     * Final serial list the invoice layer must receive. Empty until P4 allocates.
     *
     * @return list<string>
     */
    public function allocatedSerialNumbers(HardwareFulfilment $fulfilment): array
    {
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

    private function assertSerialFirst(HardwareFulfilmentState $from, HardwareFulfilmentState $to): void
    {
        if ($to === HardwareFulfilmentState::InvoiceIssued
            && $from !== HardwareFulfilmentState::SerialsAllocated) {
            throw ValidationException::withMessages([
                'state' => 'SERIALS_ALLOCATED must precede INVOICE_ISSUED.',
            ]);
        }

        if ($to === HardwareFulfilmentState::ShipmentCreated
            && $from !== HardwareFulfilmentState::InvoiceIssued) {
            throw ValidationException::withMessages([
                'state' => 'Shipment cannot start before invoice issuance and serial allocation.',
            ]);
        }
    }
}
