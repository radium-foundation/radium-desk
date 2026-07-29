<?php

namespace App\Support\Customer360;

use App\Data\Customer360\Intelligence\CaseIntelligence;
use App\Data\Customer360\Intelligence\CaseIntelligenceRisk;
use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;
use App\Data\Customer360\Intelligence\CommunicationSummary;
use App\Data\Customer360\Intelligence\CommunicationTouchpoint;
use App\Enums\AI\AIRiskLevel;
use App\Models\Incident;
use Illuminate\Support\Str;

/**
 * Builds the Phase-1 CaseIntelligence aggregate from an existing snapshot.
 * Deterministic projection only — does not re-run builders or reasoning.
 */
class CaseIntelligenceAssembler
{
    public function enabled(): bool
    {
        return (bool) config('ira.v2.enabled', false);
    }

    public function fromSnapshot(CaseIntelligenceSnapshot $snapshot, Incident $incident): CaseIntelligence
    {
        $incident->loadMissing(['assignee']);

        $narrative = $this->narrativePlain($snapshot->executiveSummary->executiveSummary);
        $assigned = trim((string) ($snapshot->engineerName ?? $incident->assignee?->name ?? ''));

        return new CaseIntelligence(
            incidentId: $snapshot->incidentId,
            orderId: $snapshot->orderId,
            generatedAt: $snapshot->generatedAt,
            schemaVersion: CaseIntelligence::SCHEMA_VERSION,
            currentStatusCode: $snapshot->currentStatusCode,
            currentStatusLabel: $snapshot->currentStatusLabel,
            isWaiting: $snapshot->isWaiting,
            waitingParty: $snapshot->waitingParty,
            waitingReasonLabel: $snapshot->waitingReasonLabel,
            waitingSince: $snapshot->waitingSince,
            assignedAgentName: $assigned !== '' ? $assigned : null,
            communicationCounts: $this->communicationCounts($snapshot->communicationSummary),
            communication: $snapshot->communicationSummary,
            customerStory: $snapshot->caseStory,
            sentimentLabel: $this->sentimentLabel($snapshot->customerMoodLevel),
            riskLevel: $this->riskLevel($snapshot->risks),
            risks: $snapshot->risks,
            blockers: $snapshot->blockers,
            openQuestions: $snapshot->openQuestions,
            nextBestAction: $snapshot->recommendedAction,
            confidenceLevel: $snapshot->confidenceLevel,
            confidenceScore: $snapshot->confidenceScore,
            executiveNarrative: $narrative,
            opinion: trim($snapshot->executiveSummary->opinion, " \t\n\r\0\x0B\"'"),
            evidence: $snapshot->evidence,
            sourceSnapshotSchemaVersion: $snapshot->schemaVersion,
        );
    }

    /**
     * @return array{whatsapp: int, email: int, phone: int, telegram: int}
     */
    private function communicationCounts(?CommunicationSummary $summary): array
    {
        $counts = [
            'whatsapp' => 0,
            'email' => 0,
            'phone' => 0,
            'telegram' => 0,
        ];

        if ($summary === null) {
            return $counts;
        }

        foreach ($summary->touchpoints as $touchpoint) {
            if (! $touchpoint instanceof CommunicationTouchpoint) {
                continue;
            }

            $channel = strtolower($touchpoint->channel);

            if (array_key_exists($channel, $counts)) {
                $counts[$channel]++;
            }
        }

        return $counts;
    }

    private function sentimentLabel(string $customerMoodLevel): string
    {
        $mood = strtolower(trim($customerMoodLevel));

        if ($mood === '' || $mood === 'unknown') {
            return 'Unknown';
        }

        return Str::headline($mood);
    }

    /**
     * @param  list<CaseIntelligenceRisk>  $risks
     */
    private function riskLevel(array $risks): string
    {
        if ($risks === []) {
            return 'none';
        }

        $rank = [
            AIRiskLevel::Low->value => 1,
            AIRiskLevel::Medium->value => 2,
            AIRiskLevel::High->value => 3,
        ];

        $max = 0;
        $level = 'none';

        foreach ($risks as $risk) {
            $value = $risk->severity->value;
            $score = $rank[$value] ?? 0;

            if ($score > $max) {
                $max = $score;
                $level = $value;
            }
        }

        return $level;
    }

    /**
     * @param  list<string>|string  $lines
     */
    private function narrativePlain(array|string $lines): string
    {
        if (is_string($lines)) {
            return trim($lines, " \t\n\r\0\x0B\"'");
        }

        $parts = [];

        foreach ($lines as $line) {
            $text = trim((string) $line, " \t\n\r\0\x0B\"'");

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(' ', $parts);
    }
}
