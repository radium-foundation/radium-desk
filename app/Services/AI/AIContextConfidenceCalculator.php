<?php

namespace App\Services\AI;

use App\Data\AI\AIConfidenceResultDTO;
use App\Data\AI\AIContextDTO;
use App\Enums\AI\AIConfidenceLevel;
use Illuminate\Support\Str;

class AIContextConfidenceCalculator
{
    public function calculate(AIContextDTO $context): AIConfidenceResultDTO
    {
        $score = 0;
        $factors = [];

        if (filled($context->customerPhone)) {
            $score += 10;
            $factors[] = 'Customer phone available';
        } else {
            $factors[] = 'Missing customer phone';
        }

        if ($context->orderId !== null) {
            $score += 10;
            $factors[] = 'Order linked';
        } else {
            $factors[] = 'No linked order';
        }

        if (! $context->serialMissing) {
            $score += 15;
            $factors[] = 'Device serial identified';
        } else {
            $factors[] = 'Serial number missing';
        }

        if (filled($context->deviceModel)) {
            $score += 10;
            $factors[] = 'Device model identified';
        } else {
            $factors[] = 'Device model unavailable';
        }

        if ($context->lastPayment !== null) {
            $score += 10;
            $factors[] = 'Payment history available';
        } else {
            $factors[] = 'No payment record';
        }

        if (! Str::contains(Str::lower($context->warrantyStatus), 'not available')) {
            $score += 10;
            $factors[] = 'Warranty status known';
        } else {
            $factors[] = 'Warranty status unknown';
        }

        if (($context->customerIntelligence->lifetimeOrderCount ?? 0) > 0) {
            $score += 10;
            $factors[] = 'Customer purchase history available';
        }

        if ($context->customerIntelligence->repeatIssueDetected) {
            $score += 5;
            $factors[] = 'Repeat issue pattern detected';
        }

        if ($context->recentActivities !== []) {
            $score += 10;
            $factors[] = 'Recent activity timeline available';
        }

        if ($context->internalRemarksCount > 0) {
            $score += 5;
            $factors[] = 'Internal remarks available';
        }

        if ($context->customerIntelligence->lastInteractionAt !== null) {
            $score += 5;
            $factors[] = 'Last interaction recorded';
        }

        $score = min(100, max(0, $score));

        return new AIConfidenceResultDTO(
            level: match (true) {
                $score >= 75 => AIConfidenceLevel::High,
                $score >= 45 => AIConfidenceLevel::Medium,
                default => AIConfidenceLevel::Low,
            },
            score: $score,
            factors: $factors,
        );
    }
}
