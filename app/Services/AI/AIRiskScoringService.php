<?php

namespace App\Services\AI;

use App\Data\AI\AIContextDTO;
use App\Data\AI\AIRiskIndicatorDTO;
use App\Enums\AI\AIRiskLevel;
use Illuminate\Support\Str;

class AIRiskScoringService
{
    /**
     * @return list<AIRiskIndicatorDTO>
     */
    public function score(AIContextDTO $context): array
    {
        $indicators = [];

        if ($this->isHighSlaRisk($context)) {
            $indicators[] = new AIRiskIndicatorDTO('High SLA Risk', AIRiskLevel::High);
        }

        if ($context->operationalIntelligence->repeatContactHighUrgency) {
            $indicators[] = new AIRiskIndicatorDTO('Repeat Contact Risk', AIRiskLevel::High);
        }

        if ($context->customerIntelligence->repeatIssueDetected) {
            $indicators[] = new AIRiskIndicatorDTO('Repeat Failure Risk', AIRiskLevel::High);
        }

        if ($context->highPriority || ($context->customerIntelligence->lifetimeRepairCount >= 3 && $context->customerSummary['open_cases'] > 1)) {
            $indicators[] = new AIRiskIndicatorDTO('Customer Escalation Risk', AIRiskLevel::Medium);
        }

        if ($this->isPaymentRisk($context)) {
            $indicators[] = new AIRiskIndicatorDTO('Payment Risk', AIRiskLevel::Medium);
        }

        if ($this->isWarrantyAbuseRisk($context)) {
            $indicators[] = new AIRiskIndicatorDTO('Warranty Abuse Risk', AIRiskLevel::Medium);
        }

        if ($context->serialMissing || ! filled($context->customerPhone)) {
            $indicators[] = new AIRiskIndicatorDTO('Data Quality Risk', AIRiskLevel::Medium);
        }

        if ($context->waitingState !== null && ! $context->serialMissing) {
            $indicators[] = new AIRiskIndicatorDTO('Waiting on Customer', AIRiskLevel::Low);
        }

        if (Str::contains(Str::lower($context->warrantyStatus), 'expired')) {
            $indicators[] = new AIRiskIndicatorDTO('Warranty Expired', AIRiskLevel::Low);
        }

        if ($indicators === []) {
            $indicators[] = new AIRiskIndicatorDTO('No Elevated Risk Detected', AIRiskLevel::Low);
        }

        return $indicators;
    }

    private function isHighSlaRisk(AIContextDTO $context): bool
    {
        $sla = Str::lower($context->operationalIntelligence->slaState);

        return Str::contains($sla, 'overdue')
            || ($context->highPriority && Str::contains($sla, 'warning'));
    }

    private function isPaymentRisk(AIContextDTO $context): bool
    {
        if ($context->lastPayment === null) {
            return true;
        }

        return Str::contains(
            Str::lower($context->customerIntelligence->paymentBehaviour),
            ['no payment', 'outstanding', 'refund pending'],
        );
    }

    private function isWarrantyAbuseRisk(AIContextDTO $context): bool
    {
        return Str::contains(Str::lower($context->warrantyStatus), 'expired')
            && $context->deviceIntelligence->previousRepairsOnSerial >= 2;
    }
}
