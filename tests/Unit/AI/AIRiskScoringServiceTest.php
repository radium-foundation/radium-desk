<?php

namespace Tests\Unit\AI;

use App\Enums\AI\AIRiskLevel;
use App\Services\AI\AIRiskScoringService;
use Tests\Support\AIContextFactory;
use Tests\TestCase;

class AIRiskScoringServiceTest extends TestCase
{
    public function test_scores_high_sla_risk_when_overdue(): void
    {
        $context = AIContextFactory::make([
            'highPriority' => true,
            'operationalIntelligence' => new \App\Data\AI\OperationalIntelligenceDTO(
                waitingState: null,
                slaState: 'Overdue',
                priority: 'High',
                assignment: 'Agent',
                queuePosition: 1,
                automationHistory: [],
                automationStatus: 'Assigned to Agent',
                timelineSummary: '3 timeline event(s).',
                internalRemarksSummary: 'No internal remarks recorded.',
            ),
        ]);

        $indicators = app(AIRiskScoringService::class)->score($context);
        $labels = array_map(fn ($indicator) => $indicator->label, $indicators);

        $this->assertContains('High SLA Risk', $labels);
        $this->assertTrue(
            collect($indicators)->contains(
                fn ($indicator) => $indicator->label === 'High SLA Risk' && $indicator->level === AIRiskLevel::High,
            ),
        );
    }

    public function test_scores_repeat_failure_risk(): void
    {
        $context = AIContextFactory::make([
            'customerIntelligence' => new \App\Data\AI\CustomerIntelligenceDTO(
                lifetimeOrderCount: 2,
                lifetimeRepairCount: 3,
                isPremiumCustomer: false,
                warrantyHistorySummary: 'Current warranty: Active.',
                repeatIssueDetected: true,
                repeatIssueSummary: 'Repeat issue detected.',
                averageRepairTurnaroundDays: 2.0,
                lastInteractionAt: null,
                lastInteractionSummary: null,
                outstandingBalance: 0.0,
                paymentBehaviour: 'Consistent payer',
            ),
        ]);

        $labels = array_map(
            fn ($indicator) => $indicator->label,
            app(AIRiskScoringService::class)->score($context),
        );

        $this->assertContains('Repeat Failure Risk', $labels);
    }

    public function test_scores_data_quality_risk_when_serial_missing(): void
    {
        $context = AIContextFactory::make([
            'serialMissing' => true,
        ]);

        $labels = array_map(
            fn ($indicator) => $indicator->label,
            app(AIRiskScoringService::class)->score($context),
        );

        $this->assertContains('Data Quality Risk', $labels);
    }
}
