<?php

namespace App\Services\Customer360\Intelligence\Builders;

use App\Data\AI\AIIncidentBundle;
use App\Data\AI\AIRiskIndicatorDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceRisk;
use App\Data\Operations\OperationsInsightDTO;
use App\Enums\AI\AIRiskLevel;
use Illuminate\Support\Str;

/**
 * Normalizes case risks from existing deterministic scorers/insights.
 */
class CaseRiskBuilder
{
    /**
     * @param  list<OperationsInsightDTO>  $operationsAdvisorInsights
     * @return array{risks: list<CaseIntelligenceRisk>, supervisor_insights: list<string>}
     */
    public function build(AIIncidentBundle $bundle, array $operationsAdvisorInsights): array
    {
        $risks = [];
        $supervisorInsights = [];

        foreach ($bundle->response->riskIndicators as $indicator) {
            if (! $indicator instanceof AIRiskIndicatorDTO) {
                continue;
            }

            if (Str::contains(Str::lower($indicator->label), 'no elevated risk')) {
                continue;
            }

            $risks[] = new CaseIntelligenceRisk(
                key: Str::slug($indicator->label, '_'),
                label: $indicator->label,
                category: $this->categoryFromLabel($indicator->label),
                severity: $indicator->level,
                source: 'ai_risk_scoring',
            );
        }

        foreach ($operationsAdvisorInsights as $insight) {
            if (! $insight instanceof OperationsInsightDTO) {
                continue;
            }

            $risks[] = new CaseIntelligenceRisk(
                key: Str::slug($insight->title, '_'),
                label: $insight->title,
                category: $insight->category->value,
                severity: $insight->severity,
                confidenceScore: $insight->confidenceScore,
                source: 'operations_advisor',
            );

            if (in_array($insight->severity, [AIRiskLevel::High, AIRiskLevel::Medium], true)) {
                $supervisorInsights[] = $insight->title.': '.$insight->recommendation;
            }
        }

        return [
            'risks' => $this->deduplicate($risks),
            'supervisor_insights' => array_values(array_unique($supervisorInsights)),
        ];
    }

    private function categoryFromLabel(string $label): string
    {
        $normalized = Str::lower($label);

        return match (true) {
            Str::contains($normalized, 'sla') => 'sla',
            Str::contains($normalized, 'payment') => 'payment',
            Str::contains($normalized, 'warranty') => 'warranty',
            Str::contains($normalized, ['repeat', 'escalation', 'customer']) => 'customer',
            Str::contains($normalized, ['data', 'serial']) => 'data_quality',
            Str::contains($normalized, 'waiting') => 'waiting',
            default => 'operational',
        };
    }

    /**
     * @param  list<CaseIntelligenceRisk>  $risks
     * @return list<CaseIntelligenceRisk>
     */
    private function deduplicate(array $risks): array
    {
        $seen = [];
        $unique = [];

        foreach ($risks as $risk) {
            if (isset($seen[$risk->key])) {
                continue;
            }

            $seen[$risk->key] = true;
            $unique[] = $risk;
        }

        return $unique;
    }
}
