<?php

namespace App\Support\Timeline;

use App\Data\TimelineEvent;
use App\Enums\BusinessMilestoneType;
use Illuminate\Support\Str;

final class BusinessTimelineTitlePresenter
{
    /**
     * @param  list<TimelineEvent>  $events
     * @return array{title: string, summary: string}
     */
    public function present(BusinessMilestoneType $type, array $events, bool $isCluster): array
    {
        $primary = $events[0] ?? null;
        $count = count($events);

        if ($primary === null) {
            return ['title' => $type->label().'.', 'summary' => ''];
        }

        if ($isCluster && $count > 1) {
            return [
                'title' => $this->clusterTitle($type, $count, $events),
                'summary' => $this->clusterSummary($type, $events),
            ];
        }

        return [
            'title' => $this->singleTitle($type, $primary),
            'summary' => $this->singleSummary($type, $primary),
        ];
    }

    /**
     * @param  list<TimelineEvent>  $events
     */
    private function clusterTitle(BusinessMilestoneType $type, int $count, array $events): string
    {
        return match ($type) {
            BusinessMilestoneType::OutboundWhatsApp => $count.' WhatsApp reminders',
            BusinessMilestoneType::OutboundEmail => $count.' support emails',
            BusinessMilestoneType::OutboundCall => $count.' calls',
            BusinessMilestoneType::OwnershipChange,
            BusinessMilestoneType::EngineerAssignment => $count.' ownership updates',
            BusinessMilestoneType::InternalNote => $count.' internal notes',
            BusinessMilestoneType::CustomerReply => $count.' customer replies',
            BusinessMilestoneType::CustomerContact => $count.' customer contacts',
            BusinessMilestoneType::SystemUpdate => $count.' system updates',
            default => $count.' '.$type->label().' events',
        };
    }

    /**
     * @param  list<TimelineEvent>  $events
     */
    private function clusterSummary(BusinessMilestoneType $type, array $events): string
    {
        $latest = $events[0];

        return match ($type) {
            BusinessMilestoneType::OutboundWhatsApp,
            BusinessMilestoneType::OutboundEmail => $this->templateOrSubject($latest) ?: 'Latest: '.$this->cleanTitle($latest->title),
            BusinessMilestoneType::OwnershipChange,
            BusinessMilestoneType::EngineerAssignment => 'Latest: '.$this->cleanTitle($latest->title),
            default => $this->cleanTitle($latest->title),
        };
    }

    private function singleTitle(BusinessMilestoneType $type, TimelineEvent $event): string
    {
        $actor = trim($event->actor->displayName);
        $clean = $this->cleanTitle($event->title);

        return match ($type) {
            BusinessMilestoneType::CaseCreated => 'Customer created service request.',
            BusinessMilestoneType::Appointment => $this->appointmentTitle($clean),
            BusinessMilestoneType::OutboundWhatsApp => $this->outboundTitle($actor, 'WhatsApp', $clean),
            BusinessMilestoneType::OutboundEmail => $this->outboundTitle($actor, 'support email', $clean),
            BusinessMilestoneType::OutboundCall => $this->callTitle($actor, $clean, outbound: true),
            BusinessMilestoneType::CustomerContact => $this->callTitle($actor, $clean, outbound: false),
            BusinessMilestoneType::CustomerReply => $this->replyTitle($event, $clean),
            BusinessMilestoneType::WaitingStarted => 'Waiting started.',
            BusinessMilestoneType::WaitingCleared => 'Waiting cleared.',
            BusinessMilestoneType::PaymentReceived => 'Payment received.',
            BusinessMilestoneType::SerialPending => 'Serial number still pending.',
            BusinessMilestoneType::SerialVerified => 'Serial number verified.',
            BusinessMilestoneType::SlaBreached => 'SLA breached.',
            BusinessMilestoneType::Escalation => 'Case escalated.',
            BusinessMilestoneType::Closure => 'Case closed.',
            BusinessMilestoneType::RepairStarted => 'Repair started.',
            BusinessMilestoneType::RepairCompleted => 'Repair completed.',
            BusinessMilestoneType::EngineerAssignment,
            BusinessMilestoneType::OwnershipChange => $this->assignmentTitle($clean),
            BusinessMilestoneType::InternalNote => 'Internal note added.',
            default => Str::finish($clean !== '' ? $clean : $type->label(), '.'),
        };
    }

    private function singleSummary(BusinessMilestoneType $type, TimelineEvent $event): string
    {
        if (filled($event->contextLine)) {
            return trim($event->contextLine);
        }

        $template = $this->templateOrSubject($event);
        if ($template !== null) {
            return $template;
        }

        if (filled($event->summary) && $event->summary !== $event->title) {
            return trim($event->summary);
        }

        foreach ($event->summaryFields as $field) {
            $label = trim((string) ($field['label'] ?? ''));
            $value = trim((string) ($field['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                return $label.': '.$value;
            }
        }

        return match ($type) {
            BusinessMilestoneType::OutboundWhatsApp,
            BusinessMilestoneType::OutboundEmail => 'Sent by '.trim($event->actor->displayName),
            default => '',
        };
    }

    private function appointmentTitle(string $clean): string
    {
        if (str_contains(strtolower($clean), 'book')) {
            return 'Appointment booked.';
        }

        if (str_contains(strtolower($clean), 'overdue')) {
            return 'Appointment overdue.';
        }

        if (str_contains(strtolower($clean), 'complet')) {
            return 'Appointment completed.';
        }

        return $clean !== '' ? Str::finish($clean, '.') : 'Appointment updated.';
    }

    private function outboundTitle(string $actor, string $channel, string $clean): string
    {
        $who = $actor !== '' ? $actor : 'Support';

        if (str_contains(strtolower($clean), 'reminder')) {
            return $who.' sent '.$channel.' reminder.';
        }

        return $who.' sent '.$channel.'.';
    }

    private function callTitle(string $actor, string $clean, bool $outbound): string
    {
        if ($outbound) {
            $who = $actor !== '' && strcasecmp($actor, 'Customer') !== 0 ? $actor : 'Support';

            return $who.' called the customer.';
        }

        if ($actor !== '' && strcasecmp($actor, 'Customer') !== 0) {
            return 'Customer spoke with '.$actor.'.';
        }

        return $clean !== '' ? Str::finish($clean, '.') : 'Customer contact recorded.';
    }

    private function replyTitle(TimelineEvent $event, string $clean): string
    {
        if (str_starts_with(strtolower($event->dedupeKey), 'incoming_email:')) {
            return 'Customer replied by email';
        }

        if ($event->type->value === 'whatsapp') {
            return 'Customer replied on WhatsApp.';
        }

        return $clean !== '' ? Str::finish($clean, '.') : 'Customer replied.';
    }

    private function assignmentTitle(string $clean): string
    {
        if (preg_match('/assigned to\s+(.+)$/i', $clean, $matches) === 1) {
            return 'Assigned to '.trim($matches[1], " \t.").'.';
        }

        return $clean !== '' ? Str::finish($clean, '.') : 'Ownership updated.';
    }

    private function cleanTitle(string $title): string
    {
        $title = trim(str_replace(['📝 ', '⚠ ', '✖ ', '✓ '], '', $title));

        return preg_replace('/\s+/', ' ', $title) ?? $title;
    }

    private function templateOrSubject(TimelineEvent $event): ?string
    {
        foreach ($event->summaryFields as $field) {
            $label = strtolower((string) ($field['label'] ?? ''));
            $value = trim((string) ($field['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if (str_contains($label, 'template') || str_contains($label, 'subject')) {
                return $value;
            }
        }

        if (filled($event->summary) && ! str_contains(strtolower($event->summary), 'http')) {
            return trim((string) $event->summary);
        }

        return null;
    }
}
