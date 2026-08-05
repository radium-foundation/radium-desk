<?php

namespace App\Support\Customer360;

use App\Contracts\Context\ProvidesContextScope;
use App\Data\AI\AIWorkbenchDTO;
use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceBlocker;
use App\Data\Customer360\Intelligence\CaseIntelligenceEvidence;
use App\Data\Customer360\Intelligence\CaseIntelligenceRisk;
use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;
use App\Data\Customer360\Intelligence\CommunicationJourneyEntry;
use App\Data\Customer360\Intelligence\CommunicationSummary;
use App\Data\Customer360\Intelligence\CommunicationTouchpoint;
use App\Data\TimelineEvent;
use App\Enums\AI\AIRiskLevel;
use App\Enums\ContextScope;
use App\Enums\SerialInsightStatus;
use App\Enums\TimelineEventType;
use App\Models\Incident;
use App\Support\Customer360\Customer360AgentLanguagePresenter;
use App\Support\AppDateFormatter;
use App\Support\Context\DeclaresContextScope;
use App\Support\DeviceModelFormatter;
use Illuminate\Support\Str;

/**
 * Thin IRA Case Intelligence panel presenter.
 * Formats CaseIntelligenceSnapshot for Blade — no domain queries, no business rules.
 */
class Customer360IraPanelPresenter implements ProvidesContextScope
{
    use DeclaresContextScope;

    private const TIMELINE_PREVIEW_LIMIT = 6;

    private const COMMUNICATION_ITEM_LIMIT = 8;

    private const JOURNEY_ITEM_LIMIT = 8;

    public function __construct(
        private readonly ExecutiveSummaryPersonEmphasis $personEmphasis,
    ) {}

    public function contextScope(): ContextScope
    {
        return ContextScope::Case;
    }

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
        ?AIWorkbenchDTO $workbench = null,
    ): array {
        $incident->loadMissing(['order', 'assignee']);
        $executiveSummary = $snapshot->executiveSummary;
        $personNames = $this->personNames($snapshot, $incident);
        $narrativePlain = $this->narrativePlain($executiveSummary->executiveSummary);
        $narrativeHtml = $this->personEmphasis->emphasize($narrativePlain, $personNames);
        $communicationItems = $this->communicationItems($snapshot->communicationSummary, $personNames);
        $journeyItems = $this->customerJourneyItems($snapshot->communicationSummary);
        $contributors = $this->caseContributors($snapshot, $incident);
        $serialInsight = $executiveSummary->serialInsight;
        $requestCorrectSerialMenu = RequestCorrectSerialMenuPresenter::resolve(
            $canRequestCorrectSerial,
            $correctSerialRequestState,
        );
        $workbench ??= $snapshot->workbench;

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

        $actionCenter = $this->actionCenter(
            snapshot: $snapshot,
            incident: $incident,
            actionText: $actionText,
            workbench: $workbench,
            hasSerialAction: $hasSerialAction,
            serialActionLabel: $requestCorrectSerialMenu['status'] === 're-request'
                ? 'Re-request serial'
                : 'Send request',
            serialRequestPending: $requestCorrectSerialMenu['status'] === 'pending',
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
            'customer_journey_items' => $journeyItems,
            'has_customer_journey' => $journeyItems !== [],
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
            'action_center' => $actionCenter,
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
                    'label' => 'Assigned To',
                    'value' => (string) $owner,
                ] : null,
                [
                    'label' => 'Current Stage',
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
            'customer_journey_items' => [],
            'has_customer_journey' => false,
            'case_contributors' => filled($owner) ? [[
                'role' => 'Assigned To',
                'name' => $owner,
                'name_html' => '<strong class="c360-ira-person">'.e($owner).'</strong>',
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
            'action_center' => [
                'primary_label' => 'Next action',
                'primary_text' => trim($executiveSummary->recommendation, " \t\n\r\0\x0B\"'"),
                'why' => trim($executiveSummary->opinion, " \t\n\r\0\x0B\"'"),
                'quick_actions' => [
                    'whatsapp' => null,
                    'email' => null,
                    'internal_note' => null,
                ],
                'suggested_reply' => null,
                'internal_note' => null,
                'checklist' => [],
                'has_serial_action' => $hasSerialAction,
                'serial_action_label' => $requestCorrectSerialMenu['status'] === 're-request'
                    ? 'Re-request serial'
                    : 'Send request',
                'serial_request_pending' => $requestCorrectSerialMenu['status'] === 'pending',
                'audit_url' => null,
                'provider_label' => null,
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
        $caseDelay = Customer360AgentLanguagePresenter::caseDelayBrief(
            $snapshot->slaStatus,
            $incident->created_at,
        );
        $waitingSince = $snapshot->waitingSince !== null
            ? AppDateFormatter::waitingDuration($snapshot->waitingSince)
            : null;
        $appointment = Customer360AgentLanguagePresenter::appointmentConditionBrief(
            $snapshot->currentStatusCode,
            $snapshot->supportAppointment,
        );
        $stageLabel = Customer360AgentLanguagePresenter::currentStageLabel(
            $snapshot->currentStatusCode,
            $snapshot->currentStatusLabel,
        );

        return array_values(array_filter([
            $customer !== '' ? ['label' => 'Customer', 'value' => $customer] : null,
            filled($product) ? ['label' => 'Product', 'value' => (string) $product] : null,
            $owner !== '' ? ['label' => 'Assigned To', 'value' => $owner] : null,
            [
                'label' => 'Current Stage',
                'value' => $stageLabel,
                'tone' => $this->statusTone($snapshot),
            ],
            in_array($priority, ['high', 'critical'], true) || $incident->high_priority
                ? [
                    'label' => 'Priority',
                    'value' => Customer360AgentLanguagePresenter::agentPriorityLabel(
                        $snapshot->priorityLevel,
                        (bool) $incident->high_priority,
                    ),
                    'tone' => $priority === 'critical' ? 'danger' : 'warning',
                ]
                : null,
            $caseDelay !== null ? [
                'label' => $caseDelay['label'],
                'value' => $caseDelay['value'],
                'tone' => $caseDelay['tone'],
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
     * Chronological operator journey — enough to skip Timeline for routine work.
     *
     * @return list<array{at_label: string, text: string, channel: ?string, kind: string}>
     */
    private function customerJourneyItems(?CommunicationSummary $communication): array
    {
        if ($communication === null || $communication->isEmpty()) {
            return [];
        }

        $items = [];

        if ($communication->communicationJourney !== []) {
            $entries = array_slice(
                $communication->communicationJourney,
                -self::JOURNEY_ITEM_LIMIT,
            );

            foreach ($entries as $entry) {
                if (! $entry instanceof CommunicationJourneyEntry) {
                    continue;
                }

                $items[] = [
                    'at_label' => AppDateFormatter::format($entry->occurredAt, 'd M, h:i A')
                        ?? $entry->dateLabel,
                    'text' => rtrim($entry->narrative, '.'),
                    'channel' => $entry->channel !== 'other' ? $entry->channel : null,
                    'kind' => $entry->channel === 'other' ? 'system' : 'event',
                ];
            }
        } else {
            $touchpoints = array_slice($communication->touchpoints, -self::JOURNEY_ITEM_LIMIT);

            foreach ($touchpoints as $touchpoint) {
                $items[] = [
                    'at_label' => AppDateFormatter::format($touchpoint->occurredAt, 'd M, h:i A')
                        ?? $touchpoint->occurredAt->format('d M, h:i A'),
                    'text' => $this->journeyTextFromTouchpoint($touchpoint),
                    'channel' => $touchpoint->channel,
                    'kind' => $touchpoint->direction === 'inbound' ? 'customer' : 'outbound',
                ];
            }
        }

        $ourLast = $communication->ourLastContact;
        $customerLast = $communication->customerLastReply;
        if ($ourLast !== null
            && $ourLast->direction === 'outbound'
            && ($customerLast === null || $customerLast->occurredAt->lt($ourLast->occurredAt))) {
            $channel = match ($ourLast->channel) {
                'whatsapp' => 'WhatsApp',
                'email' => 'email',
                'phone' => 'call',
                default => 'message',
            };
            $items[] = [
                'at_label' => '',
                'text' => 'No customer reply after the last '.$channel.'.',
                'channel' => null,
                'kind' => 'gap',
            ];
        } elseif (filled($communication->sinceLastCustomerReplyLabel)) {
            $items[] = [
                'at_label' => '',
                'text' => rtrim((string) $communication->sinceLastCustomerReplyLabel, '.'),
                'channel' => null,
                'kind' => 'gap',
            ];
        }

        return $items;
    }

    private function journeyTextFromTouchpoint(CommunicationTouchpoint $touchpoint): string
    {
        $actor = $touchpoint->direction === 'inbound'
            ? 'Customer'
            : (string) ($touchpoint->actorName ?? 'Support');

        $detail = match (true) {
            $touchpoint->channel === 'whatsapp' && filled($touchpoint->templateName) => trim(
                'sent WhatsApp template "'.$touchpoint->templateName.'"'
                .(filled($touchpoint->language) ? ' ('.$touchpoint->language.')' : ''),
            ),
            $touchpoint->channel === 'whatsapp' && $touchpoint->direction === 'inbound'
                && filled($touchpoint->preview) => 'replied on WhatsApp: "'.$touchpoint->preview.'"',
            $touchpoint->channel === 'email' && $touchpoint->direction === 'outbound'
                && filled($touchpoint->subject) => 'sent support email "'.$touchpoint->subject.'"',
            $touchpoint->channel === 'email' && $touchpoint->direction === 'inbound'
                && filled($touchpoint->subject) => 'emailed: "'.$touchpoint->subject.'"',
            $touchpoint->channel === 'phone' => filled($touchpoint->outcome)
                ? 'spoke with '.$actor.' — '.$touchpoint->outcome
                : 'spoke with '.$actor,
            default => rtrim($touchpoint->summary, '.'),
        };

        if ($touchpoint->channel === 'phone' && str_starts_with($detail, 'spoke with')) {
            return $detail;
        }

        if ($touchpoint->direction === 'inbound' && $touchpoint->channel !== 'whatsapp') {
            return $detail;
        }

        if (str_starts_with(strtolower($detail), strtolower($actor))) {
            return $detail;
        }

        return $actor.' '.$detail;
    }

    /**
     * @return array{
     *     primary_label: string,
     *     primary_text: string,
     *     why: string,
     *     quick_actions: array{whatsapp: ?string, email: ?string, internal_note: ?string},
     *     suggested_reply: ?string,
     *     internal_note: ?string,
     *     checklist: list<array{key: string, label: string, done: bool, explanation: string}>,
     *     has_serial_action: bool,
     *     serial_action_label: string,
     *     serial_request_pending: bool,
     *     audit_url: ?string,
     *     provider_label: ?string
     * }
     */
    private function actionCenter(
        CaseIntelligenceSnapshot $snapshot,
        Incident $incident,
        string $actionText,
        AIWorkbenchDTO $workbench,
        bool $hasSerialAction,
        string $serialActionLabel,
        bool $serialRequestPending,
    ): array {
        $repliesByChannel = [];
        foreach ($workbench->customerReplies as $reply) {
            $repliesByChannel[$reply['channel']] = (string) ($reply['content'] ?? '');
        }

        $whatsapp = $repliesByChannel['whatsapp'] ?? null;
        $email = $repliesByChannel['email'] ?? null;
        $internalFromReplies = $repliesByChannel['internal_note'] ?? null;
        $internalNote = trim((string) ($workbench->internalNote['content'] ?? '')) !== ''
            ? (string) $workbench->internalNote['content']
            : $internalFromReplies;

        $why = trim((string) ($snapshot->recommendedAction->rationale[0] ?? ''));
        if ($why === '') {
            $why = trim($snapshot->executiveSummary->opinion, " \t\n\r\0\x0B\"'");
        }
        if ($why === '') {
            $why = $actionText;
        }

        $checklist = array_map(
            static function (array $item): array {
                return [
                    'key' => (string) ($item['key'] ?? ''),
                    'label' => (string) ($item['label'] ?? ''),
                    'done' => (bool) ($item['done'] ?? false),
                    'explanation' => (string) ($item['explanation'] ?? ''),
                ];
            },
            $workbench->checklist,
        );

        $provider = strtolower(trim($workbench->providerName));
        $providerLabel = ($provider === '' || $provider === 'null') ? null : $workbench->providerName;

        return [
            'primary_label' => filled($snapshot->recommendedAction->label)
                ? $snapshot->recommendedAction->label
                : 'Primary action',
            'primary_text' => $actionText,
            'why' => $why,
            'quick_actions' => [
                'whatsapp' => filled($whatsapp) ? $whatsapp : null,
                'email' => filled($email) ? $email : null,
                'internal_note' => filled($internalNote) ? $internalNote : null,
            ],
            'suggested_reply' => filled($whatsapp) ? $whatsapp : (filled($email) ? $email : null),
            'internal_note' => filled($internalNote) ? $internalNote : null,
            'checklist' => $checklist,
            'has_serial_action' => $hasSerialAction,
            'serial_action_label' => $serialActionLabel,
            'serial_request_pending' => $serialRequestPending,
            'audit_url' => route('dashboard.service-cases.customer-360.ai-workbench.audit', $incident),
            'provider_label' => $providerLabel,
        ];
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
            $push('Assigned To', $currentOwner, 'bi-person-fill', 'owner');
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
