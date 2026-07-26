<?php

namespace App\Support\Customer360;

use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceBlocker;
use App\Data\Customer360\Intelligence\CaseIntelligenceEvidence;
use App\Data\Customer360\Intelligence\CaseIntelligenceRisk;
use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;
use App\Data\Customer360\Intelligence\CommunicationSummary;
use App\Data\Customer360\Intelligence\CommunicationTouchpoint;
use App\Data\TimelineEvent;
use App\Enums\AI\AIRiskLevel;
use App\Enums\SerialInsightStatus;
use App\Enums\TimelineEventType;
use App\Models\Incident;
use App\Support\AppDateFormatter;
use App\Support\DeviceModelFormatter;
use Illuminate\Support\Str;

/**
 * Thin IRA Case Intelligence panel presenter.
 * Formats CaseIntelligenceSnapshot for Blade — no domain queries, no business rules.
 */
class Customer360IraPanelPresenter
{
    private const TIMELINE_PREVIEW_LIMIT = 6;

    private const COMMUNICATION_ITEM_LIMIT = 8;

    public function __construct(
        private readonly ExecutiveSummaryPersonEmphasis $personEmphasis,
    ) {}

    /**
     * @param  array{requested?: bool, requested_at_label?: string|null}  $correctSerialRequestState
     * @return array<string, mixed>
     */
    public function present(
        CaseIntelligenceSnapshot $snapshot,
        Incident $incident,
        bool $canRequestCorrectSerial = false,
        array $correctSerialRequestState = ['requested' => false],
        ?string $translateUrl = null,
    ): array {
        $incident->loadMissing(['order', 'assignee']);
        $executiveSummary = $snapshot->executiveSummary;
        $personNames = $this->personNames($snapshot, $incident);
        $narrativePlain = $this->narrativePlain($executiveSummary->executiveSummary);
        $narrativeHtml = $this->personEmphasis->emphasize($narrativePlain, $personNames);
        $communicationItems = $this->communicationItems($snapshot->communicationSummary, $personNames);
        $contributors = $this->caseContributors($snapshot, $incident);
        $serialInsight = $executiveSummary->serialInsight;
        $requestCorrectSerialMenu = RequestCorrectSerialMenuPresenter::resolve(
            $canRequestCorrectSerial,
            $correctSerialRequestState,
        );

        $hasSerialAction = $serialInsight?->isActionable()
            && in_array($serialInsight->status, [
                SerialInsightStatus::Suspicious,
                SerialInsightStatus::Warning,
            ], true)
            && in_array($requestCorrectSerialMenu['status'], ['available', 're-request'], true);

        $actionText = trim(
            (string) ($snapshot->recommendedAction->recommendationText
                ?? $snapshot->recommendedAction->label),
            " \t\n\r\0\x0B\"'",
        );

        return [
            'heading' => 'IRA',
            'subtitle' => 'Operations briefing',
            'translate_url' => $translateUrl,
            'summary_payload' => [
                // Single narrative paragraph for translation consumers.
                'executive_summary' => $narrativePlain !== '' ? [$narrativePlain] : [],
                'opinion' => $executiveSummary->opinion,
                'recommendation' => $actionText,
            ],
            'executive_brief' => $this->executiveBrief($snapshot, $incident),
            'executive_narrative_html' => $narrativeHtml,
            'executive_narrative_plain' => $narrativePlain,
            // Backward-compatible aliases used by older tests / consumers.
            'executive_summary_lines' => $narrativePlain !== '' ? [$narrativeHtml] : [],
            'executive_summary_allows_html' => true,
            'executive_paragraph' => $narrativePlain,
            'communication_items' => $communicationItems,
            'has_communication' => $communicationItems !== [],
            'case_contributors' => $contributors,
            'has_contributors' => $contributors !== [],
            'current_status' => [
                'code' => $snapshot->currentStatusCode,
                'label' => $snapshot->currentStatusLabel,
                'tone' => $this->statusTone($snapshot),
            ],
            'waiting' => [
                'party' => $this->waitingPartyLabel($snapshot->waitingParty),
                'party_code' => $snapshot->waitingParty,
                'since_label' => $snapshot->waitingSince !== null
                    ? AppDateFormatter::waitingDuration($snapshot->waitingSince)
                    : null,
                'reason_label' => $snapshot->waitingReasonLabel,
                'is_waiting' => $snapshot->isWaiting,
            ],
            'blockers' => $this->blockers($snapshot->blockers),
            'has_blockers' => $snapshot->blockers !== [],
            'risks' => $this->risks($snapshot->risks),
            'has_risks' => $snapshot->risks !== [],
            'recommended_action' => [
                'key' => $snapshot->recommendedAction->actionKey,
                'label' => $snapshot->recommendedAction->label,
                'text' => $actionText,
                'confidence' => $snapshot->recommendedAction->confidence,
                'rationale' => $snapshot->recommendedAction->rationale,
                'secondary_actions' => $snapshot->recommendedAction->secondaryActions,
                'has_serial_action' => $hasSerialAction,
                'serial_action_label' => $requestCorrectSerialMenu['status'] === 're-request'
                    ? 'Re-request serial'
                    : 'Send request',
                'serial_request_pending' => $requestCorrectSerialMenu['status'] === 'pending',
            ],
            'evidence' => $this->evidence($snapshot),
            'opinion' => trim($executiveSummary->opinion, " \t\n\r\0\x0B\"'"),
            'serial_insight' => $serialInsight,
            'timeline_events' => $this->timelinePreview($snapshot),
            'timeline_total' => $snapshot->timeline?->totalCount ?? 0,
            'incident_id' => $incident->id,
        ];
    }

    /**
     * Legacy fallback when the engine flag is off — formats DTO-only surfaces.
     *
     * @param  list<array{title: string, source: string, tone: string}>|null  $evidenceItems
     * @param  array{requested?: bool, requested_at_label?: string|null}  $correctSerialRequestState
     * @return array<string, mixed>
     */
    public function presentFromExecutiveSummary(
        IRAExecutiveSummaryDTO $executiveSummary,
        Incident $incident,
        ?array $evidenceItems = null,
        bool $canRequestCorrectSerial = false,
        array $correctSerialRequestState = ['requested' => false],
        ?string $translateUrl = null,
    ): array {
        $incident->loadMissing(['order', 'assignee']);
        $summaryLines = $executiveSummary->executiveSummary;
        $narrativePlain = $this->narrativePlain($summaryLines);
        $serialInsight = $executiveSummary->serialInsight;
        $requestCorrectSerialMenu = RequestCorrectSerialMenuPresenter::resolve(
            $canRequestCorrectSerial,
            $correctSerialRequestState,
        );
        $hasSerialAction = $serialInsight?->isActionable()
            && in_array($serialInsight->status, [
                SerialInsightStatus::Suspicious,
                SerialInsightStatus::Warning,
            ], true)
            && in_array($requestCorrectSerialMenu['status'], ['available', 're-request'], true);

        $evidence = is_array($evidenceItems) ? $evidenceItems : [];
        $owner = $incident->assignee?->name;
        $product = DeviceModelFormatter::shortDisplay($incident->order?->device_model)
            ?: DeviceModelFormatter::shortDisplay($incident->order?->product_name)
            ?: null;

        return [
            'heading' => 'IRA',
            'subtitle' => 'Operations briefing',
            'translate_url' => $translateUrl,
            'summary_payload' => [
                'executive_summary' => $narrativePlain !== '' ? [$narrativePlain] : $summaryLines,
                'opinion' => $executiveSummary->opinion,
                'recommendation' => $executiveSummary->recommendation,
            ],
            'executive_brief' => array_values(array_filter([
                filled($incident->order?->customer_name) ? [
                    'label' => 'Customer',
                    'value' => (string) $incident->order->customer_name,
                ] : null,
                filled($product) ? [
                    'label' => 'Product',
                    'value' => (string) $product,
                ] : null,
                filled($owner) ? [
                    'label' => 'Current Owner',
                    'value' => (string) $owner,
                ] : null,
                [
                    'label' => 'Current Status',
                    'value' => $incident->status->label(),
                ],
            ])),
            'executive_narrative_html' => e($narrativePlain),
            'executive_narrative_plain' => $narrativePlain,
            'executive_summary_lines' => $summaryLines,
            'executive_summary_allows_html' => false,
            'executive_paragraph' => $narrativePlain,
            'communication_items' => [],
            'has_communication' => false,
            'case_contributors' => filled($owner) ? [[
                'role' => 'Current Owner',
                'name' => $owner,
                'icon' => 'bi-person-fill',
                'kind' => 'owner',
            ]] : [],
            'has_contributors' => filled($owner),
            'current_status' => [
                'code' => 'unknown',
                'label' => $incident->status->label(),
                'tone' => 'neutral',
            ],
            'waiting' => [
                'party' => $incident->activeWaitingState !== null ? 'Customer' : 'Nobody',
                'party_code' => $incident->activeWaitingState !== null ? 'customer' : 'none',
                'since_label' => null,
                'reason_label' => null,
                'is_waiting' => $incident->activeWaitingState !== null,
            ],
            'blockers' => [],
            'has_blockers' => false,
            'risks' => [],
            'has_risks' => false,
            'recommended_action' => [
                'key' => 'contact_customer',
                'label' => 'Next action',
                'text' => trim($executiveSummary->recommendation, " \t\n\r\0\x0B\"'"),
                'confidence' => 'medium',
                'rationale' => [],
                'secondary_actions' => [],
                'has_serial_action' => $hasSerialAction,
                'serial_action_label' => $requestCorrectSerialMenu['status'] === 're-request'
                    ? 'Re-request serial'
                    : 'Send request',
                'serial_request_pending' => $requestCorrectSerialMenu['status'] === 'pending',
            ],
            'evidence' => array_map(
                fn (array $item): array => [
                    'id' => null,
                    'title' => (string) ($item['title'] ?? ''),
                    'source' => (string) ($item['source'] ?? ''),
                    'tone' => (string) ($item['tone'] ?? 'positive'),
                    'anchor' => null,
                ],
                $evidence,
            ),
            'opinion' => trim($executiveSummary->opinion, " \t\n\r\0\x0B\"'"),
            'serial_insight' => $serialInsight,
            'timeline_events' => [],
            'timeline_total' => 0,
            'incident_id' => $incident->id,
        ];
    }

    /**
     * @return list<array{label: string, value: string, tone?: string}>
     */
    private function executiveBrief(CaseIntelligenceSnapshot $snapshot, Incident $incident): array
    {
        $context = $snapshot->aiBundle->context;
        $customer = trim((string) ($context->customerName ?? $incident->order?->customer_name ?? ''));
        $product = DeviceModelFormatter::shortDisplay($context->deviceModel)
            ?: DeviceModelFormatter::shortDisplay($incident->order?->product_name)
            ?: DeviceModelFormatter::shortDisplay($incident->order?->device_model);
        $owner = trim((string) ($snapshot->engineerName ?? $incident->assignee?->name ?? ''));
        $priority = strtolower($snapshot->priorityLevel);
        $sla = $this->slaBriefValue($snapshot, $incident);
        $waitingSince = $snapshot->waitingSince !== null
            ? AppDateFormatter::waitingDuration($snapshot->waitingSince)
            : null;
        $appointment = $this->appointmentBriefValue($snapshot);

        return array_values(array_filter([
            $customer !== '' ? ['label' => 'Customer', 'value' => $customer] : null,
            filled($product) ? ['label' => 'Product', 'value' => (string) $product] : null,
            $owner !== '' ? ['label' => 'Current Owner', 'value' => $owner] : null,
            [
                'label' => 'Current Status',
                'value' => $snapshot->currentStatusLabel,
                'tone' => $this->statusTone($snapshot),
            ],
            in_array($priority, ['high', 'critical'], true) || $incident->high_priority
                ? [
                    'label' => 'Priority',
                    'value' => $incident->high_priority && $priority === 'normal'
                        ? 'High'
                        : Str::headline($snapshot->priorityLevel),
                    'tone' => $priority === 'critical' ? 'danger' : 'warning',
                ]
                : null,
            $sla !== null ? [
                'label' => 'SLA',
                'value' => $sla['value'],
                'tone' => $sla['tone'],
            ] : null,
            ($snapshot->isWaiting && filled($waitingSince)) ? [
                'label' => 'Waiting Since',
                'value' => Str::title((string) $waitingSince),
                'tone' => 'warning',
            ] : null,
            $appointment !== null ? [
                'label' => 'Appointment',
                'value' => $appointment['value'],
                'tone' => $appointment['tone'],
            ] : null,
        ]));
    }

    /**
     * @return array{value: string, tone: string}|null
     */
    private function slaBriefValue(CaseIntelligenceSnapshot $snapshot, Incident $incident): ?array
    {
        $sla = strtolower($snapshot->slaStatus);

        if ($sla === 'overdue') {
            $pending = AppDateFormatter::waitingDuration($incident->created_at);
            $value = filled($pending)
                ? 'Overdue ('.Str::title((string) $pending).')'
                : 'Overdue';

            return ['value' => $value, 'tone' => 'danger'];
        }

        return match (true) {
            $sla === 'warning' => ['value' => 'At risk', 'tone' => 'warning'],
            $sla === 'paused' => ['value' => 'Paused', 'tone' => 'warning'],
            default => null,
        };
    }

    /**
     * @return array{value: string, tone: string}|null
     */
    private function appointmentBriefValue(CaseIntelligenceSnapshot $snapshot): ?array
    {
        if ($snapshot->currentStatusCode === 'appointment_overdue') {
            return ['value' => 'Overdue', 'tone' => 'danger'];
        }

        $appointment = $snapshot->supportAppointment;
        if (! is_array($appointment)) {
            return null;
        }

        if ($appointment['is_completed'] ?? false) {
            return null;
        }

        if ($appointment['is_active'] ?? false) {
            $date = $appointment['preferred_date'] ?? null;
            $label = $date instanceof \Illuminate\Support\Carbon
                ? $date->format('M j, Y')
                : (filled($date) ? (string) $date : 'Scheduled');

            return ['value' => $label, 'tone' => 'info'];
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function narrativePlain(array $lines): string
    {
        $parts = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (str_starts_with(strtolower($trimmed), 'next action:')) {
                continue;
            }
            if (str_starts_with($trimmed, 'Customer journey:')
                || str_contains(strtolower($trimmed), 'confidence:')
                || str_contains($trimmed, 'c360-ira-comm')) {
                continue;
            }
            $parts[] = rtrim($trimmed, '.');
        }

        if ($parts === []) {
            return '';
        }

        return implode('. ', $parts).'.';
    }

    /**
     * @param  list<string>  $personNames
     * @return list<array{
     *     actor_html: string,
     *     actor_plain: string,
     *     channel: ?string,
     *     detail_html: string,
     *     detail_plain: string,
     *     kind: string
     * }>
     */
    private function communicationItems(
        ?CommunicationSummary $communication,
        array $personNames,
    ): array {
        if ($communication === null || $communication->isEmpty()) {
            return [];
        }

        $touchpoints = array_slice($communication->touchpoints, -self::COMMUNICATION_ITEM_LIMIT);
        $items = [];

        foreach ($touchpoints as $touchpoint) {
            $items[] = $this->communicationItemFromTouchpoint($touchpoint, $personNames);
        }

        $ourLast = $communication->ourLastContact;
        $customerLast = $communication->customerLastReply;
        if ($ourLast !== null
            && $ourLast->direction === 'outbound'
            && ($customerLast === null || $customerLast->occurredAt->lt($ourLast->occurredAt))) {
            $items[] = [
                'actor_html' => e('Customer'),
                'actor_plain' => 'Customer',
                'channel' => null,
                'detail_html' => e('No reply yet'),
                'detail_plain' => 'No reply yet',
                'kind' => 'customer',
            ];
        }

        return $items;
    }

    /**
     * @param  list<string>  $personNames
     * @return array{
     *     actor_html: string,
     *     actor_plain: string,
     *     channel: ?string,
     *     detail_html: string,
     *     detail_plain: string,
     *     kind: string
     * }
     */
    private function communicationItemFromTouchpoint(
        CommunicationTouchpoint $touchpoint,
        array $personNames,
    ): array {
        $actorPlain = $touchpoint->direction === 'inbound'
            ? 'Customer'
            : (string) ($touchpoint->actorName ?? 'Support');

        $channel = match ($touchpoint->channel) {
            'whatsapp' => 'WhatsApp',
            'email' => 'Email',
            'phone' => 'Phone Call',
            default => null,
        };

        $detailPlain = match (true) {
            $touchpoint->channel === 'whatsapp' && filled($touchpoint->templateName) => trim(
                $touchpoint->templateName
                .(filled($touchpoint->language) ? ' ('.$touchpoint->language.')' : ''),
            ),
            $touchpoint->channel === 'whatsapp' && $touchpoint->direction === 'inbound'
                && filled($touchpoint->preview) => '"'.$touchpoint->preview.'"',
            $touchpoint->channel === 'email' && filled($touchpoint->subject) => (string) $touchpoint->subject,
            $touchpoint->channel === 'phone' && filled($touchpoint->outcome) => (string) $touchpoint->outcome,
            default => rtrim($touchpoint->summary, '.'),
        };

        return [
            'actor_html' => $this->personEmphasis->emphasize($actorPlain, $personNames),
            'actor_plain' => $actorPlain,
            'channel' => $channel,
            'detail_html' => $this->personEmphasis->emphasize($detailPlain, $personNames),
            'detail_plain' => $detailPlain,
            'kind' => $touchpoint->direction === 'inbound' ? 'customer' : 'outbound',
        ];
    }

    /**
     * @return list<array{role: string, name: string, name_html: string, icon: string, kind: string}>
     */
    private function caseContributors(CaseIntelligenceSnapshot $snapshot, Incident $incident): array
    {
        $contributors = [];
        $seen = [];

        $push = function (string $role, string $name, string $icon, string $kind) use (&$contributors, &$seen): void {
            $name = trim($name);
            if ($name === '') {
                return;
            }
            $key = strtolower($name);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $contributors[] = [
                'role' => $role,
                'name' => $name,
                'name_html' => '<strong class="c360-ira-person">'.e($name).'</strong>',
                'icon' => $icon,
                'kind' => $kind,
            ];
        };

        $currentOwner = trim((string) ($snapshot->engineerName ?? $incident->assignee?->name ?? ''));
        if ($currentOwner !== '') {
            $push('Current Owner', $currentOwner, 'bi-person-fill', 'owner');
        }

        foreach ($this->previousOwnerNames($snapshot, $currentOwner) as $previous) {
            $push('Previous Owner', $previous, 'bi-person', 'previous');
        }

        $agents = $snapshot->communicationSummary?->agentsInvolved ?? [];
        $hasIra = false;
        foreach ($agents as $agent) {
            if (strcasecmp($agent, 'IRA') === 0) {
                $hasIra = true;
                continue;
            }
            $push('Agent', $agent, 'bi-person-badge', 'agent');
        }

        if (! $hasIra) {
            foreach ($snapshot->communicationSummary?->touchpoints ?? [] as $touchpoint) {
                if (strcasecmp((string) $touchpoint->actorName, 'IRA') === 0) {
                    $hasIra = true;
                    break;
                }
            }
        }

        if ($hasIra) {
            $push('IRA', 'IRA', 'bi-robot', 'ira');
        }

        if (is_array($snapshot->supportAppointment)
            && filled($snapshot->supportAppointment['assignee_name'] ?? null)) {
            $engineer = (string) $snapshot->supportAppointment['assignee_name'];
            if (strcasecmp($engineer, $currentOwner) !== 0) {
                $push('Engineer', $engineer, 'bi-wrench', 'engineer');
            }
        }

        return $contributors;
    }

    /**
     * @return list<string>
     */
    private function previousOwnerNames(CaseIntelligenceSnapshot $snapshot, string $currentOwner): array
    {
        if ($snapshot->timeline === null || $snapshot->timeline->isEmpty()) {
            return [];
        }

        $names = $snapshot->timeline->events()
            ->filter(fn (TimelineEvent $event): bool => $event->type === TimelineEventType::Assignment)
            ->sortBy(fn (TimelineEvent $event): int => $event->occurredAt->timestamp)
            ->map(function (TimelineEvent $event): ?string {
                if (preg_match('/assigned to\s+(.+)$/i', $event->title, $matches) === 1) {
                    return trim($matches[1]);
                }

                $name = trim($event->actor->displayName);

                return $name !== '' && strcasecmp($name, 'System') !== 0 ? $name : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        return array_values(array_filter(
            $names,
            fn (string $name): bool => strcasecmp($name, $currentOwner) !== 0
                && strcasecmp($name, 'IRA') !== 0,
        ));
    }

    /**
     * @return list<string>
     */
    private function personNames(CaseIntelligenceSnapshot $snapshot, Incident $incident): array
    {
        $names = $snapshot->communicationSummary?->agentsInvolved ?? [];

        if (filled($snapshot->engineerName)) {
            $names[] = $snapshot->engineerName;
        }

        if (filled($incident->assignee?->name)) {
            $names[] = $incident->assignee->name;
        }

        if (is_array($snapshot->supportAppointment)
            && filled($snapshot->supportAppointment['assignee_name'] ?? null)) {
            $names[] = (string) $snapshot->supportAppointment['assignee_name'];
        }

        foreach ($this->previousOwnerNames($snapshot, '') as $previous) {
            $names[] = $previous;
        }

        return array_values(array_unique(array_filter($names)));
    }

    private function waitingPartyLabel(string $party): string
    {
        return match ($party) {
            'customer' => 'Customer',
            'engineer' => 'Engineer',
            'internal', 'internal_team' => 'Internal Team',
            default => 'Nobody',
        };
    }

    private function statusTone(CaseIntelligenceSnapshot $snapshot): string
    {
        return match (true) {
            $snapshot->slaStatus === 'overdue',
            $snapshot->currentStatusCode === 'sla_overdue',
            $snapshot->currentStatusCode === 'appointment_overdue' => 'danger',
            $snapshot->isWaiting,
            $snapshot->currentStatusCode === 'blocked_serial' => 'warning',
            $snapshot->currentStatusCode === 'scheduled' => 'info',
            $snapshot->currentStatusCode === 'closed' => 'muted',
            default => 'neutral',
        };
    }

    /**
     * @param  list<CaseIntelligenceBlocker>  $blockers
     * @return list<array{key: string, label: string, party: string, severity: string}>
     */
    private function blockers(array $blockers): array
    {
        return array_map(
            fn (CaseIntelligenceBlocker $blocker): array => [
                'key' => $blocker->key,
                'label' => $blocker->label,
                'party' => $this->waitingPartyLabel($blocker->party),
                'severity' => $blocker->severity,
            ],
            $blockers,
        );
    }

    /**
     * @param  list<CaseIntelligenceRisk>  $risks
     * @return list<array{key: string, label: string, level: string, level_label: string, explanation: string}>
     */
    private function risks(array $risks): array
    {
        return array_map(
            function (CaseIntelligenceRisk $risk): array {
                $level = $risk->severity;

                return [
                    'key' => $risk->key,
                    'label' => $risk->label,
                    'level' => $level->value,
                    'level_label' => match ($level) {
                        AIRiskLevel::High => 'High',
                        AIRiskLevel::Medium => 'Medium',
                        AIRiskLevel::Low => 'Low',
                    },
                    'explanation' => $risk->label,
                ];
            },
            $risks,
        );
    }

    /**
     * @return list<array{id: ?string, title: string, source: string, tone: string, anchor: string}>
     */
    private function evidence(CaseIntelligenceSnapshot $snapshot): array
    {
        if ($snapshot->evidence !== []) {
            return array_map(
                fn (CaseIntelligenceEvidence $item): array => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'source' => $item->source,
                    'tone' => $item->tone,
                    'anchor' => 'ira-evidence-'.$item->id,
                ],
                $snapshot->evidence,
            );
        }

        return array_map(
            fn (array $item, int $index): array => [
                'id' => null,
                'title' => (string) ($item['title'] ?? ''),
                'source' => (string) ($item['source'] ?? ''),
                'tone' => (string) ($item['tone'] ?? 'positive'),
                'anchor' => 'ira-evidence-'.$index,
            ],
            $snapshot->evidenceForView(),
            array_keys($snapshot->evidenceForView()),
        );
    }

    /**
     * @return list<array{title: string, occurred_at_label: string, type: string}>
     */
    private function timelinePreview(CaseIntelligenceSnapshot $snapshot): array
    {
        if ($snapshot->timeline === null || $snapshot->timeline->isEmpty()) {
            return [];
        }

        return $snapshot->timeline
            ->events()
            ->sortByDesc(fn (TimelineEvent $event) => $event->occurredAt->getTimestamp())
            ->take(self::TIMELINE_PREVIEW_LIMIT)
            ->map(fn (TimelineEvent $event): array => [
                'title' => $event->title,
                'occurred_at_label' => AppDateFormatter::format($event->occurredAt, 'd M, H:i')
                    ?? $event->occurredAt->toDateTimeString(),
                'type' => $event->type->value,
            ])
            ->values()
            ->all();
    }
}
