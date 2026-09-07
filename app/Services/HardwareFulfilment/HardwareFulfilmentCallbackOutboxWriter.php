<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Enums\OutboxEventStatus;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\OutboxEvent;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HardwareFulfilmentCallbackOutboxWriter
{
    public const EVENT_TYPE = 'hardware.box.callback';

    public const AGGREGATE_TYPE = 'hardware_fulfilment';

    /**
     * @return list<HardwareFulfilmentState>
     */
    public static function callbackWorthyStates(): array
    {
        return [
            HardwareFulfilmentState::SerialsAllocated,
            HardwareFulfilmentState::InvoiceIssued,
            HardwareFulfilmentState::ShipmentCreated,
            HardwareFulfilmentState::AwbAssigned,
            HardwareFulfilmentState::Shipped,
        ];
    }

    public static function idempotencyKey(HardwareFulfilment $fulfilment, HardwareFulfilmentState $state): string
    {
        return 'hardware:box-callback:'.$fulfilment->id.':'.$state->value;
    }

    public function enqueue(HardwareFulfilment $fulfilment, HardwareFulfilmentEvent $event): ?OutboxEvent
    {
        if (HardwareFulfilmentEligibility::isFrozenSourceId((string) $fulfilment->source_id)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot enqueue Box callbacks.',
            ]);
        }

        $state = $event->to_state;
        if (! in_array($state, self::callbackWorthyStates(), true)) {
            return null;
        }

        if ($state === HardwareFulfilmentState::Shipped
            && $fulfilment->state !== HardwareFulfilmentState::Shipped) {
            return null;
        }

        $eventId = (string) Str::uuid();

        return OutboxEvent::query()->firstOrCreate(
            ['idempotency_key' => self::idempotencyKey($fulfilment, $state)],
            [
                'event_type' => self::EVENT_TYPE,
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => $fulfilment->id,
                'payload' => HardwareFulfilmentCallbackPayload::build($fulfilment, $event, $eventId),
                'status' => OutboxEventStatus::Pending,
                'attempts' => 0,
                'available_at' => now(),
            ],
        );
    }
}
