<?php

namespace Tests\Unit\AI;

use App\Enums\AI\AIConfidenceLevel;
use App\Services\AI\AIContextConfidenceCalculator;
use Tests\Support\AIContextFactory;
use Tests\TestCase;

class AIContextConfidenceCalculatorTest extends TestCase
{
    public function test_high_confidence_when_context_is_complete(): void
    {
        $context = AIContextFactory::make([
            'serialMissing' => false,
            'warrantyStatus' => 'Active',
            'lastPayment' => ['label' => '₹1,000.00', 'occurred_at' => now()],
            'recentActivities' => [['title' => 'Created', 'type' => 'created', 'occurred_at' => now()]],
            'internalRemarksCount' => 2,
            'customerIntelligence' => new \App\Data\AI\CustomerIntelligenceDTO(
                lifetimeOrderCount: 2,
                lifetimeRepairCount: 2,
                isPremiumCustomer: true,
                warrantyHistorySummary: 'Current warranty: Active.',
                repeatIssueDetected: false,
                repeatIssueSummary: null,
                averageRepairTurnaroundDays: 3.0,
                lastInteractionAt: now(),
                lastInteractionSummary: 'Payment received',
                outstandingBalance: 0.0,
                paymentBehaviour: 'Consistent payer',
            ),
        ]);

        $result = app(AIContextConfidenceCalculator::class)->calculate($context);

        $this->assertSame(AIConfidenceLevel::High, $result->level);
        $this->assertGreaterThanOrEqual(75, $result->score);
    }

    public function test_low_confidence_when_critical_data_missing(): void
    {
        $context = AIContextFactory::make([
            'customerPhone' => null,
            'orderId' => null,
            'serialMissing' => true,
            'deviceModel' => null,
            'lastPayment' => null,
            'warrantyStatus' => 'Not Available',
            'recentActivities' => [],
            'internalRemarksCount' => 0,
            'customerIntelligence' => new \App\Data\AI\CustomerIntelligenceDTO(
                lifetimeOrderCount: 0,
                lifetimeRepairCount: 0,
                isPremiumCustomer: false,
                warrantyHistorySummary: 'Current warranty: Not Available.',
                repeatIssueDetected: false,
                repeatIssueSummary: null,
                averageRepairTurnaroundDays: null,
                lastInteractionAt: null,
                lastInteractionSummary: null,
                outstandingBalance: 0.0,
                paymentBehaviour: 'No payments recorded',
            ),
        ]);

        $result = app(AIContextConfidenceCalculator::class)->calculate($context);

        $this->assertSame(AIConfidenceLevel::Low, $result->level);
        $this->assertLessThan(45, $result->score);
    }
}
