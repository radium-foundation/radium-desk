<?php

namespace App\Support\Timeline;

use App\Data\TimelineEvent;
use App\Enums\BusinessMilestoneType;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use Illuminate\Support\Str;

final class BusinessMilestoneClassifier
{
    public function classify(TimelineEvent $event): BusinessMilestoneType
    {
        $key = strtolower($event->dedupeKey);
        $story = strtolower((string) $event->storyKey);
        $title = strtolower($event->title);
        $context = strtolower((string) $event->contextLine);
        $haystack = $key.' '.$story.' '.$title.' '.$context;

        if (str_contains($haystack, 'waiting-lifecycle') || str_contains($haystack, 'waiting started')) {
            if (str_contains($haystack, 'cleared') || str_contains($haystack, 'resolved') || str_contains($haystack, 'ended')) {
                return BusinessMilestoneType::WaitingCleared;
            }

            return BusinessMilestoneType::WaitingStarted;
        }

        if (str_contains($haystack, 'waiting cleared') || str_contains($haystack, 'waiting resolved')) {
            return BusinessMilestoneType::WaitingCleared;
        }

        if (str_contains($haystack, 'sla') && (str_contains($haystack, 'breach') || str_contains($haystack, 'overdue'))) {
            return BusinessMilestoneType::SlaBreached;
        }

        if (str_contains($haystack, 'escalat')) {
            return BusinessMilestoneType::Escalation;
        }

        if (str_contains($haystack, 'serial') && (str_contains($haystack, 'verif') || str_contains($haystack, 'correct') || str_contains($haystack, 'validated'))) {
            if (str_contains($haystack, 'pending') || str_contains($haystack, 'missing') || str_contains($haystack, 'request')) {
                return BusinessMilestoneType::SerialPending;
            }

            return BusinessMilestoneType::SerialVerified;
        }

        if (str_contains($haystack, 'serial') && (str_contains($haystack, 'pending') || str_contains($haystack, 'missing') || str_contains($haystack, 'await'))) {
            return BusinessMilestoneType::SerialPending;
        }

        if (str_contains($haystack, 'serial')
            && (str_contains($haystack, 'number added') || str_contains($haystack, 'serial assigned'))) {
            return BusinessMilestoneType::SerialVerified;
        }

        if ($event->type === TimelineEventType::Payment || str_contains($haystack, 'payment')) {
            return BusinessMilestoneType::PaymentReceived;
        }

        if ($event->type === TimelineEventType::Appointment) {
            return BusinessMilestoneType::Appointment;
        }

        if ($event->type === TimelineEventType::ServiceCaseClosed) {
            return BusinessMilestoneType::Closure;
        }

        if ($event->type === TimelineEventType::ServiceCaseCreated
            || str_contains($haystack, 'service request created')
            || str_contains($haystack, 'support request created')) {
            return BusinessMilestoneType::CaseCreated;
        }

        if ($event->type === TimelineEventType::InternalNote) {
            return BusinessMilestoneType::InternalNote;
        }

        if ($this->isClosure($haystack, $event)) {
            return BusinessMilestoneType::Closure;
        }

        if ($this->isRepairCompleted($haystack)) {
            return BusinessMilestoneType::RepairCompleted;
        }

        if ($this->isRepairStarted($haystack)) {
            return BusinessMilestoneType::RepairStarted;
        }

        if ($event->type === TimelineEventType::Assignment) {
            return str_contains($title, 'reassign') || str_contains($title, 'ownership') || str_contains($title, 'changed')
                ? BusinessMilestoneType::OwnershipChange
                : BusinessMilestoneType::EngineerAssignment;
        }

        if ($this->isInboundCustomer($event)) {
            return $event->type === TimelineEventType::IvrCall
                ? BusinessMilestoneType::CustomerContact
                : BusinessMilestoneType::CustomerReply;
        }

        if ($event->type === TimelineEventType::IvrCall) {
            return BusinessMilestoneType::OutboundCall;
        }

        if ($event->type === TimelineEventType::WhatsApp
            || $event->type === TimelineEventType::WhatsAppTemplateSent
            || $this->channelLooksLike('whatsapp', $event)) {
            return BusinessMilestoneType::OutboundWhatsApp;
        }

        if ($event->type === TimelineEventType::Email
            || $event->type === TimelineEventType::Notification
            || $this->channelLooksLike('email', $event)
            || str_starts_with($key, 'incoming_email:')) {
            if (str_starts_with($key, 'incoming_email:') || $this->isInboundCustomer($event)) {
                return BusinessMilestoneType::CustomerReply;
            }

            return BusinessMilestoneType::OutboundEmail;
        }

        return BusinessMilestoneType::SystemUpdate;
    }

    /**
     * Stable family key used to decide whether consecutive same-type items may cluster.
     */
    public function clusterFamily(TimelineEvent $event, BusinessMilestoneType $type): string
    {
        if ($type === BusinessMilestoneType::OutboundCall || $type === BusinessMilestoneType::CustomerContact) {
            return 'call:'.Str::lower(trim($event->actor->displayName));
        }

        if ($type === BusinessMilestoneType::CustomerReply) {
            return 'reply:'.$event->type->value;
        }

        return $type->value;
    }

    private function isInboundCustomer(TimelineEvent $event): bool
    {
        if ($event->actor->kind === TimelineActorKind::Customer) {
            return true;
        }

        if (strcasecmp($event->actor->displayName, 'Customer') === 0) {
            return true;
        }

        return str_starts_with(strtolower($event->dedupeKey), 'incoming_email:');
    }

    private function channelLooksLike(string $channel, TimelineEvent $event): bool
    {
        foreach ($event->communicationChannels as $row) {
            if (str_contains(strtolower((string) ($row['label'] ?? '')), $channel)) {
                return true;
            }
        }

        return str_contains(strtolower($event->title), $channel);
    }

    private function isClosure(string $haystack, TimelineEvent $event): bool
    {
        return $event->type === TimelineEventType::AuditEvent
            && (str_contains($haystack, 'closed') || str_contains($haystack, 'resolved') || str_contains($haystack, 'closure'));
    }

    private function isRepairCompleted(string $haystack): bool
    {
        return str_contains($haystack, 'repair complete')
            || str_contains($haystack, 'repair completed')
            || str_contains($haystack, 'ready for dispatch');
    }

    private function isRepairStarted(string $haystack): bool
    {
        return str_contains($haystack, 'repair started')
            || str_contains($haystack, 'in progress')
            || str_contains($haystack, 'diagnostics');
    }
}
