<?php

namespace App\Services\Timeline;

use App\Data\TimelineEvent;
use App\Enums\TimelineEventType;
use App\Models\Order;
use App\Support\Timeline\TimelineActorPresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Customer360OperatorTimelinePresentation
{
    /** @var list<string> */
    private const HIDDEN_TITLE_EXACT = [
        'Background sync started',
        'Background sync completed',
        'Recovery retry dispatched',
        'Order created',
    ];

    public function apply(Collection $events, Order $order): Collection
    {
        $events = $events
            ->map(fn (TimelineEvent $event): TimelineEvent => $this->normalizeActor($event))
            ->values();

        $hasWaitingLifecycle = $events->contains(
            fn (TimelineEvent $event): bool => str_starts_with($event->dedupeKey, 'waiting-lifecycle:'),
        );

        return $events
            ->map(fn (TimelineEvent $event): TimelineEvent => $this->applyVisibilityRules(
                $event,
                $hasWaitingLifecycle,
            ))
            ->filter(fn (TimelineEvent $event): bool => $event->operatorVisible)
            ->sortByDesc(fn (TimelineEvent $event) => [
                $event->occurredAt->timestamp,
                $event->dedupeKey,
            ])
            ->values();
    }

    private function normalizeActor(TimelineEvent $event): TimelineEvent
    {
        $presenter = TimelineActorPresenter::for($event->actor);

        if (! $presenter->isAutomationIdentity()) {
            return $event;
        }

        return $event->withOperatorPresentation(actor: $presenter->normalizedActor());
    }

    private function applyVisibilityRules(TimelineEvent $event, bool $hasWaitingLifecycle): TimelineEvent
    {
        if ($this->shouldHideTechnicalEvent($event)) {
            return $event->withOperatorPresentation(operatorVisible: false);
        }

        if ($this->shouldHideDuplicateCommunication($event)) {
            return $event->withOperatorPresentation(operatorVisible: false);
        }

        if ($hasWaitingLifecycle && $this->shouldHideFragmentedWaitingEvent($event)) {
            return $event->withOperatorPresentation(operatorVisible: false);
        }

        if ($event->type === TimelineEventType::WhatsApp && $event->summaryFields !== []) {
            return $event->withOperatorPresentation(operatorVisible: false);
        }

        return $this->presentOperatorLayout($event);
    }

    private function shouldHideTechnicalEvent(TimelineEvent $event): bool
    {
        if (in_array($event->title, self::HIDDEN_TITLE_EXACT, true)) {
            return true;
        }

        if (str_starts_with($event->dedupeKey, 'identity-protection:')) {
            return true;
        }

        if (str_starts_with($event->dedupeKey, 'order-created:')) {
            return true;
        }

        if (in_array($event->dedupeKey, [
            'radiumbox-waiting',
            'radiumbox-verified',
            'radiumbox-recovery',
        ], true) || Str::startsWith($event->dedupeKey, [
            'radiumbox-waiting:',
            'radiumbox-verified:',
            'radiumbox-recovery:',
        ])) {
            return true;
        }

        if ($event->title === 'Automation pending'
            || str_contains(strtolower($event->title), 'automation pending')) {
            return true;
        }

        if ($event->title === 'Serial validation successful'
            || str_contains(strtolower($event->title), 'validation passed')) {
            return true;
        }

        return false;
    }

    private function shouldHideDuplicateCommunication(TimelineEvent $event): bool
    {
        if ($event->type === TimelineEventType::WhatsAppTemplateSent) {
            return true;
        }

        if ($event->type === TimelineEventType::Email
            && str_starts_with($event->dedupeKey, 'incoming_email:')) {
            return false;
        }

        if ($event->type === TimelineEventType::Email || $event->type === TimelineEventType::WhatsApp) {
            return true;
        }

        if (str_starts_with($event->dedupeKey, 'serial-correction:audit:')) {
            return true;
        }

        return false;
    }

    private function shouldHideFragmentedWaitingEvent(TimelineEvent $event): bool
    {
        if ($event->title === 'Support Reminder Sent') {
            return true;
        }

        if (in_array($event->title, [
            'Requested Correct Serial Number',
            'Requested Device Serial Number',
        ], true)) {
            return true;
        }

        if ($event->type === TimelineEventType::InternalNote
            && filled($event->noteBody)
            && $this->isWaitingLifecycleRemark($event->noteBody)) {
            return true;
        }

        return false;
    }

    private function isWaitingLifecycleRemark(string $body): bool
    {
        $normalized = strtolower(trim($body));

        return str_contains($normalized, 'closed automatically')
            || str_contains($normalized, 'customer information requested')
            || str_contains($normalized, 'no response received within 24 hours')
            || str_contains($normalized, 'customer waiting lifecycle migration cleanup');
    }

    private function presentOperatorLayout(TimelineEvent $event): TimelineEvent
    {
        $indicatorVariant = $event->indicatorVariant ?? $this->defaultIndicatorVariant($event);

        if ($this->isSupportRequestCreated($event)) {
            return $event->withOperatorPresentation(
                title: 'Support Request Created',
                contextLine: null,
                indicatorVariant: 'info',
            );
        }

        if ($event->type === TimelineEventType::Notification && $event->communicationChannels !== []) {
            return $event->withOperatorPresentation(
                contextLine: null,
                indicatorVariant: $indicatorVariant,
                statusLabel: null,
                statusVariant: null,
            );
        }

        if ($event->type === TimelineEventType::InternalNote) {
            return $event->withOperatorPresentation(
                title: 'Note',
                contextLine: null,
                indicatorVariant: 'note',
            );
        }

        if ($event->type === TimelineEventType::Email
            && str_starts_with($event->dedupeKey, 'incoming_email:')) {
            return $event->withOperatorPresentation(
                title: 'Incoming Email',
                contextLine: $event->contextLine,
                indicatorVariant: 'info',
            );
        }

        if ($event->type === TimelineEventType::Synchronization
            && $event->title === 'RadiumBox sync failed') {
            return $event->withOperatorPresentation(indicatorVariant: 'danger');
        }

        if ($event->title === 'Validation failed'
            || str_contains(strtolower($event->title), 'validation failed')) {
            return $event->withOperatorPresentation(indicatorVariant: 'danger');
        }

        return $event->withOperatorPresentation(indicatorVariant: $indicatorVariant);
    }

    private function isSupportRequestCreated(TimelineEvent $event): bool
    {
        if ($event->type !== TimelineEventType::ServiceCaseCreated) {
            return false;
        }

        return str_contains(strtolower($event->title), 'service case')
            && str_contains(strtolower($event->title), 'created');
    }

    private function defaultIndicatorVariant(TimelineEvent $event): ?string
    {
        return match ($event->type) {
            TimelineEventType::Payment => 'success',
            TimelineEventType::Assignment,
            TimelineEventType::ServiceCaseCreated => 'info',
            TimelineEventType::Appointment => 'success',
            TimelineEventType::IvrCall => 'info',
            TimelineEventType::CustomerCorrection => 'info',
            TimelineEventType::Automation => 'warning',
            default => 'muted',
        };
    }
}
