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
use App\Support\AppDateFormatter;
use App\Support\BonvoiceCallStatuses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds structured CommunicationSummary from timeline + context facts only.
 * Never invents conversations, names, or previews.
 * Filters transport/system success noise from operator-facing briefings.
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
        $sinceLabel = $this->sinceCustomerReplyLabel($customerLastReply, $ourLastContact);
        $briefingLines = $this->buildBriefingLines($touchpoints, $customerLastReply, $ourLastContact);
        $briefing = $briefingLines === []
            ? $this->emptyBriefingFallback($bundle)
            : implode(' ', $briefingLines);

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
            briefingLines: $briefingLines,
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
        $templateName = $this->templateNameFor($event);
        $language = $this->languageFor($event, $templateName);
        $preview = $this->previewFor($event, $channel, $direction);
        $outcome = $this->outcomeFor($event, $channel, $direction, $preview);

        return new CommunicationTouchpoint(
            channel: $channel,
            occurredAt: $event->occurredAt,
            direction: $direction,
            actorName: $actorName,
            summary: $this->touchpointSummary(
                $event,
                $channel,
                $direction,
                $actorName,
                $templateName,
                $language,
                $subject,
                $preview,
                $outcome,
            ),
            preview: $preview,
            subject: filled($subject) ? $subject : null,
            templateName: $templateName,
            language: $language,
            outcome: $outcome,
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

        if (strcasecmp($name, 'Template') === 0
            || $event->actor->kind === TimelineActorKind::Automation
            || in_array(strtolower($name), ['automation', 'scheduler', 'webhook', 'system', 'radium desk'], true)) {
            return 'IRA';
        }

        if (strcasecmp($name, 'Manual') === 0) {
            return 'Support';
        }

        return $name;
    }

    private function templateNameFor(TimelineEvent $event): ?string
    {
        $template = $this->fieldValue($event, 'Template');
        if (filled($template) && ! $this->isTransportNoise($template)) {
            return $template;
        }

        $title = trim($event->title);
        if ($event->type === TimelineEventType::WhatsAppTemplateSent) {
            return null;
        }

        if ($this->looksLikeTemplateTitle($title)) {
            return $this->humanizeTemplateTitle($title);
        }

        // Notification titles like "Driver Installation Guide Sent".
        if ($event->type === TimelineEventType::Notification
            && $this->channelFor($event) === 'whatsapp'
            && $title !== ''
            && ! $this->isTransportNoise($title)) {
            return $this->humanizeTemplateTitle($title);
        }

        return null;
    }

    private function languageFor(TimelineEvent $event, ?string $templateName): ?string
    {
        $explicit = $this->fieldValue($event, 'Language');
        if (filled($explicit)) {
            return $this->languageLabel($explicit);
        }

        return $this->resolveLanguageFromConfig($templateName, $this->fieldValue($event, 'Template Key'));
    }

    private function previewFor(TimelineEvent $event, string $channel, string $direction): ?string
    {
        $raw = $this->fieldValue($event, 'Preview')
            ?? $event->summary
            ?? $this->channelDetailPreview($event);

        if ($raw === null || $this->isTransportNoise($raw)) {
            // Outbound WhatsApp must never fall back to transport success text.
            if ($channel === 'whatsapp' && $direction === 'outbound') {
                return null;
            }

            return null;
        }

        $max = $channel === 'email'
            ? CommunicationSummary::EMAIL_PREVIEW_MAX_CHARS
            : CommunicationSummary::PREVIEW_MAX_CHARS;

        return $this->truncatePreview($raw, $max);
    }

    private function outcomeFor(
        TimelineEvent $event,
        string $channel,
        string $direction,
        ?string $preview,
    ): ?string {
        if ($channel !== 'phone') {
            return null;
        }

        foreach (['Outcome', 'Notes', 'Note', 'Summary'] as $label) {
            $value = $this->fieldValue($event, $label);
            if (filled($value) && ! $this->isTransportNoise($value)) {
                return $this->truncatePreview($value, 120);
            }
        }

        if ($direction === 'inbound' && filled($preview) && ! $this->isTransportNoise($preview)) {
            return $preview;
        }

        return null;
    }

    private function touchpointSummary(
        TimelineEvent $event,
        string $channel,
        string $direction,
        ?string $actorName,
        ?string $templateName,
        ?string $language,
        ?string $subject,
        ?string $preview,
        ?string $outcome,
    ): string {
        $who = $actorName ?? ($direction === 'inbound' ? 'Customer' : 'Support');
        $when = $this->relativeTimeLabel($event->occurredAt);

        if ($channel === 'whatsapp' && $direction === 'inbound') {
            return filled($preview)
                ? 'Customer replied: "'.$preview.'"'
                : 'Customer replied on WhatsApp.';
        }

        if ($channel === 'whatsapp') {
            if (filled($templateName)) {
                $lang = filled($language) ? " ({$language})" : '';

                return "{$who} sent \"{$templateName}\"{$lang} via WhatsApp{$when}.";
            }

            $purpose = $this->outboundPurpose($event);

            return "{$who} sent {$purpose} on WhatsApp{$when}.";
        }

        if ($channel === 'email' && $direction === 'inbound') {
            return filled($subject)
                ? "Customer emailed: \"{$subject}\"."
                : 'Customer emailed support.';
        }

        if ($channel === 'email') {
            if (filled($subject)) {
                return "{$who} sent: \"{$subject}\"{$when}.";
            }

            return "{$who} emailed the customer{$when}.";
        }

        if ($channel === 'phone') {
            if ($event->type === TimelineEventType::IvrCall) {
                $ivrSummary = $this->ivrCallSummary($event, $direction, $actorName, $when);

                if ($ivrSummary !== null) {
                    if (filled($outcome)) {
                        return $ivrSummary.' Outcome: '.$outcome;
                    }

                    return $ivrSummary;
                }
            }

            if ($direction === 'inbound') {
                $base = filled($actorName)
                    ? "Customer spoke with {$actorName}{$when}."
                    : "Customer called support{$when}.";
            } else {
                $base = filled($actorName)
                    ? "{$actorName} spoke with the customer{$when}."
                    : "Support called the customer{$when}.";
            }

            if (filled($outcome)) {
                return $base.' Outcome: '.$outcome;
            }

            return $base;
        }

        return "{$who} contacted the customer{$when}.";
    }

    private function ivrCallSummary(
        TimelineEvent $event,
        string $direction,
        ?string $actorName,
        string $when,
    ): ?string {
        $status = BonvoiceCallStatuses::normalize($this->fieldValue($event, 'Status'));

        if ($status === null) {
            return null;
        }

        if ($direction === 'outbound') {
            return match ($status) {
                'ANSWERED', 'COMPLETED' => filled($actorName)
                    ? "{$actorName} spoke with the customer{$when}. Call was answered."
                    : "Support called the customer{$when}. Call was answered.",
                'NOANSWER' => filled($actorName)
                    ? "{$actorName} called the customer{$when}. Call was not answered."
                    : "Support called the customer{$when}. Call was not answered.",
                'NOINPUT' => filled($actorName)
                    ? "{$actorName} called the customer{$when} but no input was received."
                    : "Support called the customer{$when} but no input was received.",
                'FAILED' => filled($actorName)
                    ? "{$actorName}'s outbound call failed{$when}."
                    : "Outbound call failed{$when}.",
                'BUSY' => filled($actorName)
                    ? "{$actorName} called the customer{$when} but the line was busy."
                    : "Support called the customer{$when} but the line was busy.",
                'CANCELLED', 'CANCELED' => filled($actorName)
                    ? "{$actorName}'s outbound call was cancelled{$when}."
                    : "Outbound call was cancelled{$when}.",
                default => null,
            };
        }

        return match ($status) {
            'ANSWERED', 'COMPLETED' => filled($actorName)
                ? "Customer spoke with {$actorName}{$when}. Call was answered."
                : "Customer called support{$when}. Call was answered.",
            'NOANSWER' => "Customer called support{$when}. Call was not answered.",
            'NOINPUT' => "Customer called support{$when} but no input was received.",
            'FAILED' => "Customer call failed{$when}.",
            'BUSY' => "Customer called but the line was busy{$when}.",
            'CANCELLED', 'CANCELED' => "Customer call was cancelled{$when}.",
            default => null,
        };
    }

    private function outboundPurpose(TimelineEvent $event): string
    {
        $title = strtolower($event->title);

        return match (true) {
            str_contains($title, 'appointment') && str_contains($title, 'reminder') => 'an appointment reminder',
            str_contains($title, 'appointment') => 'an appointment update',
            str_contains($title, 'reminder') => 'a reminder',
            str_contains($title, 'serial') => 'a serial-number request',
            str_contains($title, 'follow') => 'a follow-up',
            str_contains($title, 'callback') => 'a callback request',
            str_contains($title, 'driver') => 'a driver installation guide',
            default => 'a message',
        };
    }

    /**
     * @param  Collection<int, CommunicationTouchpoint>  $touchpoints
     * @return list<string>
     */
    private function buildBriefingLines(
        Collection $touchpoints,
        ?CommunicationTouchpoint $customerLastReply,
        ?CommunicationTouchpoint $ourLastContact,
    ): array {
        if ($touchpoints->isEmpty()) {
            return [];
        }

        $lines = [];
        $lastOutboundWhatsappAt = null;

        foreach ($touchpoints as $touchpoint) {
            $line = rtrim($touchpoint->summary, '.');

            if ($touchpoint->channel === 'email'
                && $touchpoint->direction === 'outbound'
                && filled($touchpoint->preview)) {
                $line .= '. Preview: "'.$touchpoint->preview.'"';
            }

            $lines[] = $line.'.';

            if ($touchpoint->channel === 'whatsapp' && $touchpoint->direction === 'outbound') {
                $lastOutboundWhatsappAt = $touchpoint->occurredAt;
            }

            if ($touchpoint->channel === 'whatsapp'
                && $touchpoint->direction === 'inbound'
                && $lastOutboundWhatsappAt !== null) {
                $lastOutboundWhatsappAt = null;
            }
        }

        $hasInboundAfterLastOutbound = $customerLastReply !== null
            && $ourLastContact !== null
            && $customerLastReply->occurredAt->greaterThanOrEqualTo($ourLastContact->occurredAt);

        if ($ourLastContact?->channel === 'whatsapp'
            && $ourLastContact->direction === 'outbound'
            && ! $hasInboundAfterLastOutbound
            && ($customerLastReply === null
                || $customerLastReply->occurredAt->lt($ourLastContact->occurredAt))) {
            $lines[] = 'Customer has not replied.';
        }

        return $this->compactBriefingLines($lines);
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function compactBriefingLines(array $lines): array
    {
        $unique = [];
        foreach ($lines as $line) {
            $key = Str::lower(trim($line));
            if ($key === '' || isset($unique[$key])) {
                continue;
            }
            $unique[$key] = $line;
        }

        $compact = array_values($unique);

        if (count($compact) <= CommunicationSummary::JOURNEY_MAX_ENTRIES) {
            return $compact;
        }

        $keep = array_slice($compact, -CommunicationSummary::JOURNEY_MAX_ENTRIES);
        $omitted = count($compact) - count($keep);
        array_unshift($keep, "{$omitted} earlier communication events omitted for brevity.");

        return $keep;
    }

    private function emptyBriefingFallback(AIIncidentBundle $bundle): ?string
    {
        $repeat = trim((string) ($bundle->context->operationalIntelligence->repeatContactSummary ?? ''));

        return $repeat !== '' ? $repeat : null;
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
                Str::lower(Str::limit($touchpoint->templateName ?? $touchpoint->subject ?? $touchpoint->summary, 80, '')),
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

    private function sinceCustomerReplyLabel(
        ?CommunicationTouchpoint $customerLastReply,
        ?CommunicationTouchpoint $ourLastContact,
    ): ?string {
        if ($ourLastContact !== null
            && ($customerLastReply === null || $customerLastReply->occurredAt->lt($ourLastContact->occurredAt))) {
            return 'Customer has not replied.';
        }

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
            if ($detail !== '' && ! $this->isTransportNoise($detail)) {
                return $detail;
            }
        }

        $detail = trim((string) ($event->detail ?? ''));

        return ($detail !== '' && ! $this->isTransportNoise($detail)) ? $detail : null;
    }

    private function truncatePreview(?string $preview, int $maxChars = CommunicationSummary::PREVIEW_MAX_CHARS): ?string
    {
        $preview = trim((string) $preview);
        if ($preview === '' || $this->isTransportNoise($preview)) {
            return null;
        }

        $preview = preg_replace('/\s+/', ' ', $preview) ?? $preview;

        if (Str::length($preview) <= $maxChars) {
            return $preview;
        }

        $trimmed = rtrim(
            Str::limit($preview, $maxChars - 1, ''),
            " \t\n\r\0\x0B.,;:",
        );

        return $trimmed.'…';
    }

    private function isTransportNoise(string $text): bool
    {
        $normalized = Str::lower(trim($text));

        if ($normalized === '') {
            return true;
        }

        $patterns = [
            'whatsapp template sent successfully',
            'email sent successfully',
            'notification sent successfully',
            'message sent successfully',
            'template sent successfully',
            'sent successfully',
            'delivery status unavailable',
            'queued for delivery',
            'outbox will retry',
            'aggregate_success',
            'http ',
            'status code',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return (bool) preg_match('/\b(api|queue|webhook|smtp|provider)\b.*\b(success|failed|status)\b/i', $text);
    }

    private function relativeTimeLabel(Carbon $at): string
    {
        $local = AppDateFormatter::inAppTimezone($at) ?? $at;
        $time = $local->format('g:i A');
        $days = (int) $local->copy()->startOfDay()->diffInDays(now(AppDateFormatter::timezone())->startOfDay());

        return match (true) {
            $days <= 0 => " today at {$time}",
            $days === 1 => " yesterday at {$time}",
            $days < 7 => ' on '.$local->format('l')." at {$time}",
            default => ' on '.$local->format('j M')." at {$time}",
        };
    }

    private function looksLikeTemplateTitle(string $title): bool
    {
        $lower = strtolower($title);

        return str_contains($lower, 'reminder')
            || str_contains($lower, 'appointment')
            || str_contains($lower, 'serial')
            || str_contains($lower, 'callback')
            || str_contains($lower, 'follow');
    }

    private function humanizeTemplateTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+sent$/i', '', $title) ?? $title);

        return $title !== '' ? $title : 'WhatsApp message';
    }

    private function resolveLanguageFromConfig(?string $displayName, ?string $templateKey): ?string
    {
        /** @var array<string, mixed> $templates */
        $templates = config('interakt.templates', []);

        foreach ($templates as $key => $cfg) {
            if (! is_array($cfg)) {
                continue;
            }

            if (filled($templateKey) && strcasecmp((string) $key, $templateKey) === 0) {
                return $this->languageLabel((string) ($cfg['language_code'] ?? 'en'));
            }

            if (filled($displayName)
                && strcasecmp((string) ($cfg['display_name'] ?? ''), $displayName) === 0) {
                return $this->languageLabel((string) ($cfg['language_code'] ?? 'en'));
            }

            if (filled($displayName)
                && strcasecmp((string) ($cfg['name'] ?? ''), $displayName) === 0) {
                return $this->languageLabel((string) ($cfg['language_code'] ?? 'en'));
            }
        }

        return null;
    }

    private function languageLabel(string $codeOrLabel): string
    {
        $value = strtolower(trim($codeOrLabel));

        return match (true) {
            $value === 'hi', str_contains($value, 'hindi') => 'Hindi',
            $value === 'en', str_contains($value, 'english') => 'English',
            $value !== '' => Str::headline($codeOrLabel),
            default => 'English',
        };
    }

    private function isNonAgentName(string $name): bool
    {
        $lower = strtolower($name);

        return in_array($lower, ['customer', 'template', 'system', 'radium desk', 'support', 'manual'], true)
            || str_starts_with($lower, 'customer');
    }
}
