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
