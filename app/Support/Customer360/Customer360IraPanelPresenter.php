<?php

namespace App\Support\Customer360;

use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceBlocker;
use App\Data\Customer360\Intelligence\CaseIntelligenceEvidence;
use App\Data\Customer360\Intelligence\CaseIntelligenceRisk;
use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;
use App\Data\TimelineEvent;
use App\Enums\AI\AIRiskLevel;
use App\Enums\SerialInsightStatus;
use App\Models\Incident;
use App\Support\AppDateFormatter;

/**
 * Thin IRA Case Intelligence panel presenter.
 * Formats CaseIntelligenceSnapshot for Blade — no domain queries, no business rules.
 */
class Customer360IraPanelPresenter
{
    private const TIMELINE_PREVIEW_LIMIT = 6;

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
        $executiveSummary = $snapshot->executiveSummary;
        $summaryLines = $executiveSummary->executiveSummary;
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

        return [
            'heading' => 'IRA',
            'subtitle' => 'Case intelligence',
            'translate_url' => $translateUrl,
            'summary_payload' => [
                'executive_summary' => $summaryLines,
                'opinion' => $executiveSummary->opinion,
                // Canonical recommendation from snapshot.recommendedAction (Q2).
                'recommendation' => $snapshot->recommendedAction->recommendationText
                    ?? $snapshot->recommendedAction->label,
            ],
            'executive_summary_lines' => $summaryLines,
            'executive_paragraph' => $this->executiveParagraph($summaryLines),
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
                'text' => trim(
                    (string) ($snapshot->recommendedAction->recommendationText
                        ?? $snapshot->recommendedAction->label),
                    " \t\n\r\0\x0B\"'",
                ),
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
        $summaryLines = $executiveSummary->executiveSummary;
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

        return [
            'heading' => 'IRA',
            'subtitle' => 'Case intelligence',
            'translate_url' => $translateUrl,
            'summary_payload' => [
                'executive_summary' => $summaryLines,
                'opinion' => $executiveSummary->opinion,
                'recommendation' => $executiveSummary->recommendation,
            ],
            'executive_summary_lines' => $summaryLines,
            'executive_paragraph' => $this->executiveParagraph($summaryLines),
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
     * @param  list<string>  $lines
     */
    private function executiveParagraph(array $lines): string
    {
        $filtered = array_values(array_filter(
            $lines,
            fn (string $line): bool => ! str_starts_with($line, 'Customer journey:')
                && ! str_contains(strtolower($line), 'confidence:'),
        ));

        return implode(' ', $filtered);
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
