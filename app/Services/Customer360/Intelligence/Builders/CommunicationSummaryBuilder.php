<?php

namespace App\Services\Customer360\Intelligence\Builders;

use App\Data\AI\AIIncidentBundle;
use App\Data\Customer360\Intelligence\CaseIntelligenceFacts;
use App\Data\Customer360\Intelligence\CommunicationJourneyEntry;
use App\Data\Customer360\Intelligence\CommunicationSummary;
use App\Data\Customer360\Intelligence\CommunicationTouchpoint;
use App\Data\TimelineEvent;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds structured CommunicationSummary from timeline + context facts only.
 * Never invents conversations, names, or previews.
 */
class CommunicationSummaryBuilder
{
    public function build(CaseIntelligenceFacts $facts, AIIncidentBundle $bundle): CommunicationSummary
    {
        $events = $facts->timeline->events()
            ->filter(fn (TimelineEvent $event): bool => $this->isCommunicationEvent($event))
            ->sortBy(fn (TimelineEvent $event): int => $event->occurredAt->timestamp)
            ->values();

        $touchpoints = $this->dedupeTouchpoints(
            $events->map(fn (TimelineEvent $event): CommunicationTouchpoint => $this->toTouchpoint($event)),
        );

        $latestWhatsapp = $this->latestForChannel($touchpoints, 'whatsapp');
        $latestEmail = $this->latestForChannel($touchpoints, 'email');
        $latestCall = $this->latestForChannel($touchpoints, 'phone');

        $customerLastReply = $touchpoints
            ->filter(fn (CommunicationTouchpoint $t): bool => $t->direction === 'inbound')
            ->sortByDesc(fn (CommunicationTouchpoint $t): int => $t->occurredAt->timestamp)
            ->first();

        $ourLastContact = $touchpoints
            ->filter(fn (CommunicationTouchpoint $t): bool => $t->direction === 'outbound')
            ->sortByDesc(fn (CommunicationTouchpoint $t): int => $t->occurredAt->timestamp)
            ->first();

        $channelsUsed = $touchpoints
            ->pluck('channel')
            ->unique()
            ->values()
            ->all();

        $agentsInvolved = $touchpoints
            ->map(fn (CommunicationTouchpoint $t): ?string => $t->actorName)
            ->filter(fn (?string $name): bool => filled($name) && ! $this->isNonAgentName((string) $name))
            ->unique()
            ->values()
            ->all();

        $journey = $this->buildJourney($touchpoints);
        $sinceLabel = $this->sinceCustomerReplyLabel($customerLastReply);
        $briefing = $this->buildBriefingParagraph(
            $touchpoints,
            $latestWhatsapp,
            $latestEmail,
            $latestCall,
            $customerLastReply,
            $sinceLabel,
            $bundle,
        );

        return new CommunicationSummary(
            latestWhatsapp: $latestWhatsapp,
            latestEmail: $latestEmail,
            latestCall: $latestCall,
            communicationJourney: $journey,
            customerLastReply: $customerLastReply,
            ourLastContact: $ourLastContact,
            channelsUsed: $channelsUsed,
            agentsInvolved: $agentsInvolved,
            touchpoints: $touchpoints->all(),
            briefingParagraph: $briefing,
            sinceLastCustomerReplyAt: $customerLastReply?->occurredAt,
            sinceLastCustomerReplyLabel: $sinceLabel,
        );
    }

    private function isCommunicationEvent(TimelineEvent $event): bool
    {
        return in_array($event->type, [
            TimelineEventType::WhatsApp,
            TimelineEventType::WhatsAppTemplateSent,
            TimelineEventType::Email,
            TimelineEventType::IvrCall,
            TimelineEventType::Notification,
        ], true);
    }

    private function toTouchpoint(TimelineEvent $event): CommunicationTouchpoint
    {
        $channel = $this->channelFor($event);
        $direction = $this->directionFor($event);
        $actorName = $this->actorNameFor($event, $direction);
        $subject = $this->fieldValue($event, 'Subject');
        $preview = $this->truncatePreview(
            $this->fieldValue($event, 'Preview')
                ?? $event->summary
                ?? $this->channelDetailPreview($event),
        );

        return new CommunicationTouchpoint(
            channel: $channel,
            occurredAt: $event->occurredAt,
            direction: $direction,
            actorName: $actorName,
            summary: $this->touchpointSummary($event, $channel, $direction, $actorName),
            preview: $preview,
            subject: filled($subject) ? $subject : null,
        );
    }

    private function channelFor(TimelineEvent $event): string
    {
        return match ($event->type) {
            TimelineEventType::WhatsApp,
            TimelineEventType::WhatsAppTemplateSent => 'whatsapp',
            TimelineEventType::Email => 'email',
            TimelineEventType::IvrCall => 'phone',
            TimelineEventType::Notification => $this->notificationChannel($event),
            default => 'other',
        };
    }

    private function notificationChannel(TimelineEvent $event): string
    {
        foreach ($event->communicationChannels as $channel) {
            $label = strtolower((string) ($channel['label'] ?? ''));
            if (str_contains($label, 'whatsapp')) {
                return 'whatsapp';
            }
            if (str_contains($label, 'email')) {
                return 'email';
            }
        }

        $title = strtolower($event->title);

        return match (true) {
            str_contains($title, 'email') => 'email',
            str_contains($title, 'call'), str_contains($title, 'phone') => 'phone',
            default => 'whatsapp',
        };
    }

    private function directionFor(TimelineEvent $event): string
    {
        if ($event->type === TimelineEventType::Email
            && str_contains(strtolower($event->title), 'incoming')) {
            return 'inbound';
        }

        if ($event->type === TimelineEventType::IvrCall) {
            return str_contains(strtolower($event->title), 'outbound') ? 'outbound' : 'inbound';
        }

        if ($event->actor->kind === TimelineActorKind::Customer
            || strcasecmp($event->actor->displayName, 'Customer') === 0
            || str_starts_with($event->actor->displayName, 'Customer →')) {
            if ($event->type === TimelineEventType::WhatsApp
                && strcasecmp($event->actor->displayName, 'Customer') === 0) {
                return 'inbound';
            }
            if ($event->type === TimelineEventType::IvrCall) {
                return str_contains(strtolower($event->title), 'outbound') ? 'outbound' : 'inbound';
            }
            if ($event->type === TimelineEventType::Email) {
                return 'inbound';
            }
        }

        if (in_array($event->type, [
            TimelineEventType::Notification,
            TimelineEventType::WhatsAppTemplateSent,
        ], true)) {
            return 'outbound';
        }

        if ($event->actor->kind === TimelineActorKind::Automation
            || $event->actor->isAutomation
            || strcasecmp($event->actor->displayName, 'IRA') === 0
            || strcasecmp($event->actor->displayName, 'Template') === 0) {
            return 'outbound';
        }

        return 'outbound';
    }

    private function actorNameFor(TimelineEvent $event, string $direction): ?string
    {
        if ($event->type === TimelineEventType::IvrCall) {
            $agent = $this->fieldValue($event, 'Agent');
            if (filled($agent)) {
                return $agent;
            }
            if (str_contains($event->actor->displayName, '→')) {
                return trim((string) Str::after($event->actor->displayName, '→'));
            }
        }

        if ($direction === 'inbound') {
            return null;
        }

        $name = trim($event->actor->displayName);
        if ($name === '' || strcasecmp($name, 'Customer') === 0) {
            return null;
        }

        if (strcasecmp($name, 'Template') === 0 || $event->actor->kind === TimelineActorKind::Automation) {
            return 'IRA';
        }

        if (strcasecmp($name, 'Radium Desk') === 0 || strcasecmp($name, 'System') === 0) {
            return 'IRA';
        }

        return $name;
    }

    private function touchpointSummary(
        TimelineEvent $event,
        string $channel,
        string $direction,
        ?string $actorName,
    ): string {
        $who = $actorName ?? ($direction === 'inbound' ? 'Customer' : 'Support');
        $channelLabel = match ($channel) {
            'whatsapp' => 'WhatsApp',
            'email' => 'email',
            'phone' => 'phone',
            default => 'message',
        };

        if ($direction === 'inbound' && $channel === 'phone') {
            return filled($actorName)
                ? "Customer called {$actorName}."
                : 'Customer called support.';
        }

        if ($direction === 'inbound' && $channel === 'email') {
            return 'Customer emailed support.';
        }

        if ($direction === 'inbound' && $channel === 'whatsapp') {
            return 'Customer replied on WhatsApp.';
        }

        if ($channel === 'email') {
            return "{$who} emailed the customer".($this->fieldValue($event, 'Subject')
                ? ' regarding '.$this->fieldValue($event, 'Subject')
                : '').'.';
        }

        if ($channel === 'whatsapp') {
            $purpose = $this->outboundPurpose($event);

            return "{$who} sent a WhatsApp {$purpose}.";
        }

        if ($channel === 'phone') {
            return "{$who} called the customer.";
        }

        return "{$who} contacted the customer via {$channelLabel}.";
    }

    private function outboundPurpose(TimelineEvent $event): string
    {
        $title = strtolower($event->title);

        return match (true) {
            str_contains($title, 'reminder') => 'reminder',
            str_contains($title, 'serial') => 'serial-number request',
            str_contains($title, 'follow') => 'follow-up',
            default => 'message',
        };
    }

    /**
     * @param  Collection<int, CommunicationTouchpoint>  $touchpoints
     * @return Collection<int, CommunicationTouchpoint>
     */
    private function dedupeTouchpoints(Collection $touchpoints): Collection
    {
        $seen = [];
        $unique = collect();

        foreach ($touchpoints as $touchpoint) {
            $key = implode('|', [
                $touchpoint->channel,
                $touchpoint->direction,
                $touchpoint->actorName ?? '',
                $touchpoint->occurredAt->format('Y-m-d H:i'),
                Str::lower(Str::limit($touchpoint->summary, 80, '')),
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique->push($touchpoint);
        }

        return $unique->values();
    }

    /**
     * @param  Collection<int, CommunicationTouchpoint>  $touchpoints
     */
    private function latestForChannel(Collection $touchpoints, string $channel): ?CommunicationTouchpoint
    {
        return $touchpoints
            ->filter(fn (CommunicationTouchpoint $t): bool => $t->channel === $channel)
            ->sortByDesc(fn (CommunicationTouchpoint $t): int => $t->occurredAt->timestamp)
            ->first();
    }

    /**
     * @param  Collection<int, CommunicationTouchpoint>  $touchpoints
     * @return list<CommunicationJourneyEntry>
     */
    private function buildJourney(Collection $touchpoints): array
    {
        $entries = [];

        foreach ($touchpoints as $touchpoint) {
            $entries[] = new CommunicationJourneyEntry(
                occurredAt: $touchpoint->occurredAt,
                dateLabel: $touchpoint->occurredAt->format('j M'),
                narrative: rtrim($touchpoint->summary, '.').'.',
                channel: $touchpoint->channel,
            );
        }

        if (count($entries) <= CommunicationSummary::JOURNEY_MAX_ENTRIES) {
            return $entries;
        }

        $keep = array_slice($entries, -CommunicationSummary::JOURNEY_MAX_ENTRIES);
        $omitted = count($entries) - count($keep);
        array_unshift($keep, new CommunicationJourneyEntry(
            occurredAt: $entries[0]->occurredAt,
            dateLabel: 'Earlier',
            narrative: "{$omitted} earlier customer communication event(s) omitted for brevity.",
            channel: 'other',
        ));

        return $keep;
    }

    private function sinceCustomerReplyLabel(?CommunicationTouchpoint $customerLastReply): ?string
    {
        if ($customerLastReply === null) {
            return null;
        }

        $days = (int) $customerLastReply->occurredAt->copy()->startOfDay()
            ->diffInDays(now()->startOfDay());

        if ($days <= 0) {
            return 'Customer responded today.';
        }

        if ($days === 1) {
            return 'No further customer response has been received since yesterday.';
        }

        return "No further customer response has been received for {$days} day(s).";
    }

    /**
     * @param  Collection<int, CommunicationTouchpoint>  $touchpoints
     */
    private function buildBriefingParagraph(
        Collection $touchpoints,
        ?CommunicationTouchpoint $latestWhatsapp,
        ?CommunicationTouchpoint $latestEmail,
        ?CommunicationTouchpoint $latestCall,
        ?CommunicationTouchpoint $customerLastReply,
        ?string $sinceLabel,
        AIIncidentBundle $bundle,
    ): ?string {
        if ($touchpoints->isEmpty()) {
            $repeat = trim((string) ($bundle->context->operationalIntelligence->repeatContactSummary ?? ''));

            return $repeat !== '' ? $repeat : null;
        }

        $parts = [];

        $whatsappAgents = $touchpoints
            ->filter(fn (CommunicationTouchpoint $t): bool => $t->channel === 'whatsapp' && $t->direction === 'outbound')
            ->map(fn (CommunicationTouchpoint $t): ?string => $t->actorName)
            ->filter()
            ->unique()
            ->values();

        if ($whatsappAgents->isNotEmpty()) {
            $parts[] = $this->agentSequenceSentence($whatsappAgents->all(), 'WhatsApp');
        } elseif ($latestWhatsapp !== null) {
            $parts[] = rtrim($latestWhatsapp->summary, '.');
        }

        if ($latestEmail !== null && $latestEmail->direction === 'outbound') {
            $parts[] = rtrim($latestEmail->summary, '.');
            if (filled($latestEmail->subject)) {
                $parts[count($parts) - 1] .= ' (Subject: '.$latestEmail->subject.')';
            }
        } elseif ($latestEmail !== null) {
            $parts[] = rtrim($latestEmail->summary, '.');
        }

        if ($latestCall !== null) {
            $parts[] = rtrim($latestCall->summary, '.');
            if ($latestCall->direction === 'inbound' && filled($latestCall->preview)) {
                $parts[count($parts) - 1] .= ' Customer said: "'.$latestCall->preview.'"';
            }
        }

        if ($customerLastReply !== null && filled($customerLastReply->preview)
            && $customerLastReply->channel !== 'phone') {
            $parts[] = 'Customer reply preview: "'.$customerLastReply->preview.'"';
        }

        if ($sinceLabel !== null && $customerLastReply !== null) {
            $parts[] = rtrim($sinceLabel, '.');
        }

        $parts = array_values(array_unique(array_filter($parts)));

        if ($parts === []) {
            return null;
        }

        return implode('. ', $parts).'.';
    }

    /**
     * @param  list<string>  $agents
     */
    private function agentSequenceSentence(array $agents, string $channelLabel): string
    {
        $agents = array_values(array_unique($agents));

        if ($agents === []) {
            return "Support contacted the customer on {$channelLabel}";
        }

        if (count($agents) === 1) {
            return "{$agents[0]} contacted the customer on {$channelLabel}";
        }

        if (count($agents) === 2) {
            return "{$agents[0]} contacted the customer on {$channelLabel}, followed by {$agents[1]}";
        }

        $last = array_pop($agents);
        $first = array_shift($agents);

        return "{$first} sent the first {$channelLabel} message, followed by "
            .implode(', ', $agents)
            ." and later {$last}";
    }

    private function fieldValue(TimelineEvent $event, string $label): ?string
    {
        foreach ($event->summaryFields as $field) {
            if (strcasecmp((string) ($field['label'] ?? ''), $label) === 0) {
                $value = trim((string) ($field['value'] ?? ''));

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    private function channelDetailPreview(TimelineEvent $event): ?string
    {
        foreach ($event->communicationChannels as $channel) {
            $detail = trim((string) ($channel['detail'] ?? ''));
            if ($detail !== '' && ! str_contains(strtolower($detail), 'unavailable')) {
                return $detail;
            }
        }

        $detail = trim((string) ($event->detail ?? ''));

        return $detail !== '' ? $detail : null;
    }

    private function truncatePreview(?string $preview): ?string
    {
        $preview = trim((string) $preview);
        if ($preview === '') {
            return null;
        }

        $preview = preg_replace('/\s+/', ' ', $preview) ?? $preview;

        if (Str::length($preview) <= CommunicationSummary::PREVIEW_MAX_CHARS) {
            return $preview;
        }

        $trimmed = rtrim(
            Str::limit($preview, CommunicationSummary::PREVIEW_MAX_CHARS - 1, ''),
            " \t\n\r\0\x0B.,;:",
        );

        return $trimmed.'…';
    }

    private function isNonAgentName(string $name): bool
    {
        $lower = strtolower($name);

        return in_array($lower, ['customer', 'template', 'system', 'radium desk', 'ira'], true)
            || str_starts_with($lower, 'customer');
    }
}
