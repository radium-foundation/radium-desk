<?php

namespace Tests\Unit\AI;

use App\Data\AI\AIContextDTO;
use App\Enums\AI\AIConfidenceLevel;
use App\Services\AI\AIContextConfidenceCalculator;
use App\Services\AI\Providers\NullAIProvider;
use Tests\Support\AIContextFactory;
use Tests\TestCase;

class NullAIProviderTest extends TestCase
{
    public function test_generates_deterministic_summary_for_premium_customer_with_expired_warranty(): void
    {
        $provider = new NullAIProvider;
        $context = AIContextFactory::make([
            'warrantyStatus' => 'Expired',
            'serialMissing' => true,
            'customerIntelligence' => new \App\Data\AI\CustomerIntelligenceDTO(
                lifetimeOrderCount: 3,
                lifetimeRepairCount: 3,
                isPremiumCustomer: true,
                warrantyHistorySummary: 'Current warranty: Expired.',
                repeatIssueDetected: false,
                repeatIssueSummary: null,
                averageRepairTurnaroundDays: null,
                lastInteractionAt: null,
                lastInteractionSummary: null,
                outstandingBalance: 0.0,
                paymentBehaviour: 'Consistent payer',
            ),
        ]);

        $summary = $provider->summarizeIncident($context);

        $this->assertStringContainsString('Premium customer.', $summary);
        $this->assertStringContainsString('Warranty expired.', $summary);
        $this->assertStringContainsString('Waiting for serial number.', $summary);
    }

    public function test_suggests_warranty_and_serial_actions_when_warranty_expired_and_serial_missing(): void
    {
        $provider = new NullAIProvider;
        $context = AIContextFactory::make([
            'serialMissing' => true,
            'warrantyStatus' => 'Expired',
        ]);

        $actions = $provider->suggestNextActions($context);
        $titles = array_map(fn ($action) => $action->title, $actions);

        $this->assertContains('Request serial number', $titles);
        $this->assertContains('Verify purchase invoice', $titles);
        $this->assertContains('Inform customer warranty has expired', $titles);
    }

    public function test_suggests_inspect_technician_notes_when_repeat_repair_detected(): void
    {
        $provider = new NullAIProvider;
        $context = AIContextFactory::make([
            'customerIntelligence' => new \App\Data\AI\CustomerIntelligenceDTO(
                lifetimeOrderCount: 2,
                lifetimeRepairCount: 3,
                isPremiumCustomer: true,
                warrantyHistorySummary: 'Current warranty: Active.',
                repeatIssueDetected: true,
                repeatIssueSummary: 'Repeat issue "Screen fault" seen on 1 prior case(s).',
                averageRepairTurnaroundDays: 4.0,
                lastInteractionAt: null,
                lastInteractionSummary: null,
                outstandingBalance: 0.0,
                paymentBehaviour: 'Consistent payer',
            ),
        ]);

        $titles = array_map(
            fn ($action) => $action->title,
            $provider->suggestNextActions($context),
        );

        $this->assertContains('Inspect previous technician notes', $titles);
    }

    public function test_estimates_unknown_resolution_when_waiting_on_customer(): void
    {
        $provider = new NullAIProvider;
        $context = AIContextFactory::make([
            'waitingState' => [
                'reason_label' => 'Serial Number',
                'started_at' => now(),
                'sla_paused' => true,
                'reminder_policy_label' => 'Weekly',
                'next_action_at' => null,
            ],
        ]);

        $this->assertSame('Unknown', $provider->estimateResolution($context));
    }

    public function test_explain_recommendation_returns_contextual_rationale(): void
    {
        $provider = new NullAIProvider;
        $context = AIContextFactory::make();

        $explanation = $provider->explainRecommendation($context, 'Request serial number');

        $this->assertStringContainsString('Serial number validation', $explanation);
    }
}
