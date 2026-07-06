<?php

namespace App\Services\AI\Providers;

use App\Contracts\AI\AIProvider;
use App\Data\AI\AIContextDTO;
use App\Data\AI\AIRecommendationDTO;
use Illuminate\Support\Str;

class NullAIProvider implements AIProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function summarizeIncident(AIContextDTO $context): string
    {
        $parts = [];

        if ($context->customerIntelligence->isPremiumCustomer) {
            $parts[] = 'Premium customer.';
        }

        $parts[] = $this->warrantyLine($context);
        $parts[] = $this->waitingLine($context);

        if ($context->customerIntelligence->repeatIssueDetected) {
            $parts[] = 'Repeat repair detected.';
        }

        if ($context->operationalIntelligence->slaState !== 'Within SLA') {
            $parts[] = 'SLA: '.$context->operationalIntelligence->slaState.'.';
        }

        return implode("\n", array_filter($parts));
    }

    public function suggestReply(AIContextDTO $context): string
    {
        $greeting = 'Hello'.($context->customerName ? ' '.$context->customerName : '');

        if ($context->serialMissing && $context->isWarrantyExpired()) {
            return $greeting.', thank you for contacting us regarding case '.$context->incidentReference.'. Please share your device serial number and purchase invoice so we can verify the device details. Please note that the warranty on this device appears to have expired; our team will confirm coverage after verification.';
        }

        if ($context->serialMissing) {
            return $greeting.', thank you for contacting us. To proceed with your service request, please share your device serial number. We will verify the details and update your case shortly.';
        }

        if ($context->waitingState !== null) {
            $reason = $context->waitingState['reason_label'] ?? 'information';

            return $greeting.', we are following up on your service case '.$context->incidentReference.'. We are currently waiting for '.$reason.'. Please reply when you have the requested details so we can continue.';
        }

        return $greeting.', thank you for reaching out regarding case '.$context->incidentReference.'. Our team is reviewing your request and will share the next update shortly.';
    }

    /**
     * @return list<AIRecommendationDTO>
     */
    public function suggestNextActions(AIContextDTO $context): array
    {
        $actions = [];
        $baseConfidence = $this->actionConfidence($context, 0.85);

        if ($context->serialMissing) {
            $actions[] = new AIRecommendationDTO(
                title: 'Request serial number',
                description: 'Customer device serial is missing or pending verification.',
                confidence: $baseConfidence,
                rationale: 'Serial number is required for warranty and repair validation.',
            );
            $actions[] = new AIRecommendationDTO(
                title: 'Verify model',
                description: 'Confirm device model against order records.',
                confidence: $this->actionConfidence($context, 0.75),
            );
        }

        if ($context->isWarrantyExpired() && $context->serialMissing) {
            $actions[] = new AIRecommendationDTO(
                title: 'Verify purchase invoice',
                description: 'Confirm purchase date and ownership before proceeding.',
                confidence: $this->actionConfidence($context, 0.8),
            );
            $actions[] = new AIRecommendationDTO(
                title: 'Inform customer warranty has expired',
                description: 'Set expectations about out-of-warranty repair charges.',
                confidence: $this->actionConfidence($context, 0.85),
            );
        }

        if ($context->customerIntelligence->repeatIssueDetected) {
            $actions[] = new AIRecommendationDTO(
                title: 'Inspect previous technician notes',
                description: $context->customerIntelligence->repeatIssueSummary ?? 'Prior repair history exists for this customer or device.',
                confidence: $this->actionConfidence($context, 0.8),
                rationale: 'Repeat failures may share root cause with earlier repairs.',
            );
        }

        if ($context->waitingState !== null && isset($context->waitingState['reason_label'])) {
            $actions[] = new AIRecommendationDTO(
                title: 'Follow up on waiting state',
                description: 'Case is waiting for '.$context->waitingState['reason_label'].'.',
                confidence: $this->actionConfidence($context, 0.78),
            );
        }

        if ($context->deviceIntelligence->previousRepairsOnSerial > 0 && ! $context->customerIntelligence->repeatIssueDetected) {
            $actions[] = new AIRecommendationDTO(
                title: 'Check previous repair',
                description: 'This serial has '.$context->deviceIntelligence->previousRepairsOnSerial.' prior repair(s).',
                confidence: $this->actionConfidence($context, 0.7),
            );
        }

        if ($actions === []) {
            $actions[] = new AIRecommendationDTO(
                title: 'Review incident details',
                description: 'Confirm issue description and current status.',
                confidence: $this->actionConfidence($context, 0.6),
            );
            $actions[] = new AIRecommendationDTO(
                title: 'Contact customer',
                description: 'Send a status update or request missing information.',
                confidence: $this->actionConfidence($context, 0.55),
            );
        }

        return $actions;
    }

    public function classifyIncident(AIContextDTO $context): string
    {
        if ($context->highPriority) {
            return 'High Priority / '.$context->incidentCategory;
        }

        if ($context->customerIntelligence->repeatIssueDetected) {
            return 'Repeat Repair / '.$context->incidentCategory;
        }

        if ($context->serialMissing) {
            return 'Data Collection / '.$context->incidentCategory;
        }

        if ($context->waitingState !== null) {
            return 'Waiting on Customer / '.$context->incidentCategory;
        }

        return 'Standard Support / '.$context->incidentCategory;
    }

    public function estimateResolution(AIContextDTO $context): string
    {
        if ($context->waitingState !== null || $context->serialMissing) {
            return 'Unknown';
        }

        $turnaround = $context->customerIntelligence->averageRepairTurnaroundDays;

        if ($turnaround !== null && $turnaround > 0) {
            $days = (int) ceil($turnaround);

            return $days.' day(s) based on customer history';
        }

        if ($context->highPriority) {
            return '1 business day';
        }

        return '2-3 business days';
    }

    public function explainRecommendation(AIContextDTO $context, string $recommendation): string
    {
        $normalized = Str::lower(trim($recommendation));

        if (Str::contains($normalized, 'serial')) {
            return 'Serial number validation is required before repair or warranty decisions can be made.';
        }

        if (Str::contains($normalized, 'invoice')) {
            return 'Purchase invoice verification is needed when warranty status is expired or unknown.';
        }

        if (Str::contains($normalized, 'warranty has expired')) {
            return 'Customer should be informed early to avoid disputes about chargeable repairs.';
        }

        if (Str::contains($normalized, 'technician notes') || Str::contains($normalized, 'previous repair')) {
            return 'Prior service history may indicate recurring issues or covered repairs.';
        }

        if (Str::contains($normalized, 'waiting')) {
            return 'The case cannot progress until the outstanding customer dependency is resolved.';
        }

        return 'This recommendation is derived from the enriched incident context and customer history available in the service desk.';
    }

    private function actionConfidence(AIContextDTO $context, float $base): float
    {
        $modifier = 0.0;

        if (! $context->serialMissing) {
            $modifier += 0.05;
        }

        if ($context->customerIntelligence->lastInteractionAt !== null) {
            $modifier += 0.03;
        }

        if ($context->internalRemarksCount > 0) {
            $modifier += 0.02;
        }

        return min(0.95, round($base + $modifier, 2));
    }

    private function warrantyLine(AIContextDTO $context): string
    {
        $status = Str::lower($context->warrantyStatus);

        if (Str::contains($status, 'active') || Str::contains($status, 'valid')) {
            return 'Warranty active.';
        }

        if (Str::contains($status, 'expired')) {
            return 'Warranty expired.';
        }

        return 'Warranty status unavailable.';
    }

    private function waitingLine(AIContextDTO $context): string
    {
        if ($context->serialMissing) {
            return 'Waiting for serial number.';
        }

        if ($context->waitingState !== null && isset($context->waitingState['reason_label'])) {
            return 'Waiting for '.$context->waitingState['reason_label'].'.';
        }

        return 'No active waiting state.';
    }
}
