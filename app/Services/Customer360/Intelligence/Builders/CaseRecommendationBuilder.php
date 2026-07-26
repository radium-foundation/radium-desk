<?php

namespace App\Services\Customer360\Intelligence\Builders;

use App\Data\AI\AIIncidentBundle;
use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceFacts;
use App\Data\Customer360\Intelligence\CaseIntelligenceRecommendedAction;
use App\Enums\SupportAppointmentStatus;
use Illuminate\Support\Str;

/**
 * Derives a structured recommended action from deterministic facts + summary text.
 * Does not invent business state; maps existing signals to action keys.
 */
class CaseRecommendationBuilder
{
    public function build(
        CaseIntelligenceFacts $facts,
        AIIncidentBundle $bundle,
        IRAExecutiveSummaryDTO $executiveSummary,
    ): CaseIntelligenceRecommendedAction {
        $context = $bundle->context;
        $waiting = $facts->waitingStateCard;
        $appointment = $facts->supportAppointment;

        if ($context->serialMissing) {
            return new CaseIntelligenceRecommendedAction(
                actionKey: 'request_serial',
                label: 'Request Serial',
                rationale: ['Device serial number is missing or pending validation.'],
                confidence: 'high',
                matchedRuleId: 'serial_missing',
                recommendationText: $executiveSummary->recommendation,
            );
        }

        if (is_array($waiting)) {
            return new CaseIntelligenceRecommendedAction(
                actionKey: 'wait',
                label: 'Wait',
                rationale: [
                    'Case is waiting for '.($waiting['reason_label'] ?? 'customer input').'.',
                ],
                confidence: 'high',
                matchedRuleId: 'active_waiting_state',
                recommendationText: $executiveSummary->recommendation,
            );
        }

        if (is_array($appointment) && ($appointment['status'] ?? null) === SupportAppointmentStatus::Cancelled) {
            return new CaseIntelligenceRecommendedAction(
                actionKey: 'schedule_appointment',
                label: 'Schedule Appointment',
                rationale: ['The previous support appointment was cancelled.'],
                confidence: 'high',
                matchedRuleId: 'cancelled_appointment',
                recommendationText: $executiveSummary->recommendation,
            );
        }

        if (is_array($appointment) && ($appointment['is_active'] ?? false)) {
            return new CaseIntelligenceRecommendedAction(
                actionKey: 'wait',
                label: 'Wait',
                rationale: ['Support appointment is scheduled and awaiting execution.'],
                confidence: 'medium',
                matchedRuleId: 'active_appointment',
                recommendationText: $executiveSummary->recommendation,
            );
        }

        if ($context->operationalIntelligence->repeatContactHighUrgency) {
            return new CaseIntelligenceRecommendedAction(
                actionKey: 'contact_customer',
                label: 'Contact Customer',
                rationale: ['Customer has made repeated contact attempts.'],
                confidence: 'high',
                matchedRuleId: 'repeat_contact',
                recommendationText: $executiveSummary->recommendation,
            );
        }

        $primaryAction = $bundle->response->suggestedNextActions[0] ?? null;
        if ($primaryAction !== null) {
            return new CaseIntelligenceRecommendedAction(
                actionKey: $this->actionKeyFromTitle($primaryAction->title),
                label: $primaryAction->title,
                rationale: array_values(array_filter([
                    trim($primaryAction->description) !== '' ? $primaryAction->description : null,
                    $executiveSummary->opinion,
                ])),
                confidence: 'medium',
                matchedRuleId: 'ai_provider_next_action',
                recommendationText: $executiveSummary->recommendation,
            );
        }

        return new CaseIntelligenceRecommendedAction(
            actionKey: 'contact_customer',
            label: 'Contact Customer',
            rationale: [$executiveSummary->opinion],
            confidence: 'low',
            matchedRuleId: 'default_contact',
            recommendationText: $executiveSummary->recommendation,
        );
    }

    private function actionKeyFromTitle(string $title): string
    {
        $normalized = Str::lower($title);

        return match (true) {
            Str::contains($normalized, 'serial') => 'request_serial',
            Str::contains($normalized, 'appoint') => 'schedule_appointment',
            Str::contains($normalized, 'escalat') => 'escalate',
            Str::contains($normalized, 'wait') => 'wait',
            Str::contains($normalized, ['verify', 'identity', 'correct']) => 'verify_identity',
            default => 'contact_customer',
        };
    }
}
