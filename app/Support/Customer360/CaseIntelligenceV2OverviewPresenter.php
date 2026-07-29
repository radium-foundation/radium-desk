<?php

namespace App\Support\Customer360;

use App\Contracts\Context\ProvidesContextScope;
use App\Data\Customer360\Intelligence\CaseIntelligence;
use App\Data\Customer360\Intelligence\CaseIntelligenceBlocker;
use App\Data\Customer360\Intelligence\CaseIntelligenceEvidence;
use App\Data\Customer360\Intelligence\CaseIntelligenceRisk;
use App\Data\Customer360\Intelligence\CaseStory;
use App\Enums\AI\AIRiskLevel;
use App\Enums\ContextScope;
use App\Models\Incident;
use App\Support\AppDateFormatter;
use App\Support\Context\DeclaresContextScope;
use Illuminate\Support\Str;

/**
 * Formats CaseIntelligence for the Phase-1 Signal Bar + Overview Blade.
 * Presentation only — no domain queries or business rules.
 */
class CaseIntelligenceV2OverviewPresenter implements ProvidesContextScope
{
    use DeclaresContextScope;

    public function __construct(
        private readonly ExecutiveSummaryPersonEmphasis $personEmphasis,
    ) {}

    public function contextScope(): ContextScope
    {
        return ContextScope::Case;
    }

    /**
     * @param  array<string, mixed>  $legacyPanel  Existing panel presenter output (action center, translate, etc.)
     * @return array<string, mixed>
     */
    public function present(CaseIntelligence $aggregate, Incident $incident, array $legacyPanel = []): array
    {
        $incident->loadMissing(['order', 'assignee']);
        $personNames = $this->personNames($aggregate, $incident);
        $narrativePlain = $aggregate->executiveNarrative;
        $narrativeHtml = $this->personEmphasis->emphasize($narrativePlain, $personNames);
        $actionText = $this->actionText($aggregate);

        return [
            'heading' => 'IRA',
            'subtitle' => 'Case intelligence',
            'incident_id' => $aggregate->incidentId,
            'translate_url' => $legacyPanel['translate_url'] ?? null,
            'summary_payload' => $legacyPanel['summary_payload'] ?? [
                'executive_summary' => $narrativePlain !== '' ? [$narrativePlain] : [],
                'opinion' => $aggregate->opinion,
                'recommendation' => $actionText,
            ],
            'signal_bar' => $this->signalBar($aggregate, $actionText),
            'story_sections' => $this->storySections($aggregate->customerStory),
            'executive_narrative_html' => $narrativeHtml,
            'executive_narrative_plain' => $narrativePlain,
            'opinion' => $aggregate->opinion,
            'open_questions' => array_values(array_filter(array_map(
                static fn (mixed $q): string => trim((string) $q),
                $aggregate->openQuestions,
            ))),
            'blockers' => $this->blockers($aggregate->blockers),
            'risks' => $this->risks($aggregate->risks),
            'recommended_action' => [
                'key' => $aggregate->nextBestAction->actionKey,
                'label' => $aggregate->nextBestAction->label,
                'text' => $actionText,
                'confidence' => $aggregate->nextBestAction->confidence,
                'rationale' => $aggregate->nextBestAction->rationale,
            ],
            'action_center' => $legacyPanel['action_center'] ?? null,
            'evidence' => $this->evidence($aggregate->evidence, $legacyPanel['evidence'] ?? []),
            'communication_briefing' => $aggregate->communication?->briefingParagraph,
            'schema_version' => $aggregate->schemaVersion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function signalBar(CaseIntelligence $aggregate, string $actionText): array
    {
        $waitingLabel = null;

        if ($aggregate->isWaiting && $aggregate->waitingSince !== null) {
            $duration = AppDateFormatter::waitingDuration($aggregate->waitingSince);
            $waitingLabel = filled($duration) ? Str::title((string) $duration) : null;
        }

        $counts = $aggregate->communicationCounts;

        return [
            'status' => [
                'label' => $aggregate->currentStatusLabel,
                'tone' => $this->statusTone($aggregate),
            ],
            'communication' => [
                'whatsapp' => (int) ($counts['whatsapp'] ?? 0),
                'email' => (int) ($counts['email'] ?? 0),
                'phone' => (int) ($counts['phone'] ?? 0),
                'telegram' => (int) ($counts['telegram'] ?? 0),
            ],
            'sentiment' => [
                'label' => $aggregate->sentimentLabel,
                'tone' => strtolower($aggregate->sentimentLabel) === 'unknown' ? 'muted' : 'neutral',
            ],
            'risk' => [
                'label' => $this->riskLabel($aggregate->riskLevel),
                'tone' => $this->riskTone($aggregate->riskLevel),
                'level' => $aggregate->riskLevel,
            ],
            'waiting' => [
                'label' => $waitingLabel ?? ($aggregate->isWaiting ? 'Waiting' : 'Not waiting'),
                'tone' => $aggregate->isWaiting ? 'warning' : 'muted',
                'is_waiting' => $aggregate->isWaiting,
            ],
            'assigned_agent' => [
                'label' => $aggregate->assignedAgentName ?? 'Unassigned',
                'tone' => $aggregate->assignedAgentName !== null ? 'neutral' : 'muted',
            ],
            'next_best_action' => [
                'label' => $actionText !== '' ? $actionText : ($aggregate->nextBestAction->label ?: 'None'),
                'tone' => 'info',
            ],
            'confidence' => [
                'label' => $aggregate->confidenceLevel->label(),
                'score' => $aggregate->confidenceScore,
                'tone' => match ($aggregate->confidenceLevel->value) {
                    'high' => 'positive',
                    'medium' => 'warning',
                    default => 'muted',
                },
            ],
        ];
    }

    /**
     * @return list<array{title: string, items: list<string>}>
     */
    private function storySections(?CaseStory $story): array
    {
        if ($story === null) {
            return [];
        }

        $sections = [
            ['title' => 'Current situation', 'items' => $story->currentSituation],
            ['title' => 'Progress', 'items' => $story->progress],
            ['title' => 'Blockers', 'items' => $story->blockers],
            ['title' => 'Risks', 'items' => $story->risks],
            ['title' => 'Recommended action', 'items' => $story->recommendedAction],
            ['title' => 'Supporting facts', 'items' => $story->supportingFacts],
        ];

        return array_values(array_filter(
            $sections,
            static fn (array $section): bool => $section['items'] !== [],
        ));
    }

    private function actionText(CaseIntelligence $aggregate): string
    {
        return trim(
            (string) ($aggregate->nextBestAction->recommendationText
                ?? $aggregate->nextBestAction->label),
            " \t\n\r\0\x0B\"'",
        );
    }

    private function statusTone(CaseIntelligence $aggregate): string
    {
        if ($aggregate->isWaiting) {
            return 'warning';
        }

        $code = strtolower($aggregate->currentStatusCode);

        return match (true) {
            str_contains($code, 'close'), str_contains($code, 'resolved') => 'positive',
            str_contains($code, 'escalat') => 'danger',
            default => 'neutral',
        };
    }

    private function riskLabel(string $level): string
    {
        return match ($level) {
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
            default => 'None',
        };
    }

    private function riskTone(string $level): string
    {
        return match ($level) {
            'high' => 'danger',
            'medium' => 'warning',
            'low' => 'info',
            default => 'muted',
        };
    }

    /**
     * @param  list<CaseIntelligenceBlocker>  $blockers
     * @return list<array{key: string, label: string, party: string, severity: string}>
     */
    private function blockers(array $blockers): array
    {
        return array_map(
            static fn (CaseIntelligenceBlocker $blocker): array => [
                'key' => $blocker->key,
                'label' => $blocker->label,
                'party' => Str::headline($blocker->party),
                'severity' => $blocker->severity,
            ],
            $blockers,
        );
    }

    /**
     * @param  list<CaseIntelligenceRisk>  $risks
     * @return list<array{key: string, label: string, level: string, level_label: string}>
     */
    private function risks(array $risks): array
    {
        return array_map(
            static function (CaseIntelligenceRisk $risk): array {
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
                ];
            },
            $risks,
        );
    }

    /**
     * @param  list<CaseIntelligenceEvidence>  $evidence
     * @param  list<array{title?: string, source?: string, tone?: string, anchor?: string, id?: mixed}>  $legacyEvidence
     * @return list<array{id: mixed, title: string, source: string, tone: string, anchor: string}>
     */
    private function evidence(array $evidence, array $legacyEvidence): array
    {
        if ($evidence !== []) {
            return array_map(
                static fn (CaseIntelligenceEvidence $item): array => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'source' => $item->source,
                    'tone' => $item->tone,
                    'anchor' => 'ira-evidence-'.$item->id,
                ],
                $evidence,
            );
        }

        return array_values(array_map(
            static fn (array $item, int $index): array => [
                'id' => $item['id'] ?? null,
                'title' => (string) ($item['title'] ?? ''),
                'source' => (string) ($item['source'] ?? ''),
                'tone' => (string) ($item['tone'] ?? 'positive'),
                'anchor' => (string) ($item['anchor'] ?? ('ira-evidence-'.$index)),
            ],
            $legacyEvidence,
            array_keys($legacyEvidence),
        ));
    }

    /**
     * @return list<string>
     */
    private function personNames(CaseIntelligence $aggregate, Incident $incident): array
    {
        $names = array_filter([
            $aggregate->assignedAgentName,
            $incident->assignee?->name,
            $incident->order?->customer_name,
        ]);

        return array_values(array_unique(array_map(
            static fn (mixed $name): string => trim((string) $name),
            $names,
        )));
    }
}
