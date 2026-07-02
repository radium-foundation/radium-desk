<?php

namespace App\Services\AI;

use App\Contracts\AI\AIProvider;
use App\Data\AI\AIContextBuildSnapshot;
use App\Data\AI\AIIncidentBundle;
use App\Data\AI\AIResponseDTO;
use App\Models\Incident;
use App\Services\Knowledge\KnowledgeEngine;

class AIService
{
    public function __construct(
        private readonly KnowledgeEngine $knowledgeEngine,
        private readonly IncidentAIContextBuilder $contextBuilder,
        private readonly AIProvider $provider,
        private readonly AIContextConfidenceCalculator $confidenceCalculator,
    ) {}

    public function forIncident(Incident $incident, ?AIContextBuildSnapshot $snapshot = null): AIResponseDTO
    {
        return $this->buildBundle($incident, $snapshot)->response;
    }

    public function buildBundle(
        Incident $incident,
        ?AIContextBuildSnapshot $snapshot = null,
        ?CustomerScopeQueryCache $scopeCache = null,
    ): AIIncidentBundle
    {
        $incident->loadMissing(['order']);
        $scopeCache ??= new CustomerScopeQueryCache($incident->order?->customer_phone);
        $knowledge = $this->knowledgeEngine->forIncident($incident, $snapshot, $scopeCache);
        $context = $this->contextBuilder->build($incident, $snapshot, $knowledge, $scopeCache);
        $nextActions = $this->provider->suggestNextActions($context);
        $primaryAction = $nextActions[0]->title ?? 'Review incident details';
        $confidence = $this->confidenceCalculator->calculate($context);

        $response = new AIResponseDTO(
            customerSummary: $this->buildCustomerSummary($context),
            incidentSummary: $this->provider->summarizeIncident($context),
            warrantyStatus: $context->warrantyStatus,
            paymentStatus: $this->formatPaymentStatus($context),
            riskIndicators: $context->riskIndicators,
            suggestedNextActions: $nextActions,
            suggestedCustomerReply: $this->provider->suggestReply($context),
            confidence: $confidence->normalizedScore(),
            confidenceLevel: $confidence->level,
            confidenceScore: $confidence->score,
            classification: $this->provider->classifyIncident($context),
            estimatedResolution: $this->provider->estimateResolution($context),
            recommendationExplanation: $this->provider->explainRecommendation($context, $primaryAction),
            providerName: $this->provider->name(),
            customerIntelligence: $context->customerIntelligence,
            deviceIntelligence: $context->deviceIntelligence,
            operationalIntelligence: $context->operationalIntelligence,
            businessIntelligence: $context->businessIntelligence,
            knowledge: $knowledge,
        );

        return new AIIncidentBundle(
            response: $response,
            context: $context,
            knowledge: $knowledge,
            scopeCache: $scopeCache,
        );
    }

    private function buildCustomerSummary(\App\Data\AI\AIContextDTO $context): string
    {
        $parts = array_filter([
            $context->customerName,
            filled($context->customerPhone) ? $context->customerPhone : null,
        ]);

        $identity = $parts !== [] ? implode(' · ', $parts) : 'Unknown customer';
        $intel = $context->customerIntelligence;

        return sprintf(
            '%s — %d orders, %d repairs, %d open cases. %s',
            $identity,
            $intel->lifetimeOrderCount,
            $intel->lifetimeRepairCount,
            $context->customerSummary['open_cases'] ?? 0,
            $intel->isPremiumCustomer ? 'Premium customer.' : 'Standard customer.',
        );
    }

    /**
     * @return array{label: string, occurred_at: string|null}|null
     */
    private function formatPaymentStatus(\App\Data\AI\AIContextDTO $context): ?array
    {
        if ($context->lastPayment === null) {
            return null;
        }

        return [
            'label' => $context->lastPayment['label'],
            'occurred_at' => $context->lastPayment['occurred_at']?->toIso8601String(),
        ];
    }
}
