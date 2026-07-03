<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use Illuminate\Support\Collection;

class AppointmentTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        $this->order->loadMissing('incidents.supportAppointments');

        $appointments = $this->order->incidents
            ->flatMap(fn (Incident $incident) => $incident->supportAppointments)
            ->sortByDesc(fn (SupportAppointment $appointment) => $appointment->created_at?->timestamp ?? 0)
            ->when($limit !== null, fn (Collection $collection) => $collection->take($limit));

        return $appointments
            ->map(fn (SupportAppointment $appointment): TimelineEvent => new TimelineEvent(
                type: TimelineEventType::Appointment,
                occurredAt: $appointment->created_at ?? now(),
                title: 'Appointment scheduled',
                actor: new TimelineActor(
                    displayName: 'Customer',
                    kind: TimelineActorKind::Customer,
                ),
                dedupeKey: "appointment:{$appointment->id}",
                detail: trim(implode(' • ', array_filter([
                    $appointment->preferred_date?->format('d M Y'),
                    $appointment->preferred_time_slot?->label(),
                    filled($appointment->additional_notes) ? $appointment->additional_notes : null,
                ]))),
                statusLabel: 'Booked',
                statusVariant: 'success',
                filterTags: ['appointments', 'customer'],
            ))
            ->values();
    }
}
