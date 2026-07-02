<?php

namespace App\Services\AI;

use App\Contracts\AI\AIProvider;
use App\Data\AI\AIContextDTO;
use App\Data\AI\AIIncidentBundle;
use App\Data\AI\AIResponseDTO;
use App\Data\AI\AIWorkbenchDTO;
use App\Enums\AI\AIConfidenceLevel;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use Illuminate\Support\Str;

class AIWorkbenchService
{
    public function __construct(
        private readonly AIProvider $provider,
    ) {}

    public function build(
        Incident $incident,
        AIResponseDTO $response,
        AIContextDTO $context,
    ): AIWorkbenchDTO {
        $incident->loadMissing(['order', 'assignee', 'activeWaitingState']);
        $scenario = $this->detectScenario($incident, $response, $context);
        $baseReply = $response->suggestedCustomerReply;
        $confidenceLevel = $response->confidenceLevel;
        $confidenceScore = $response->confidenceScore;
        $explanation = $response->recommendationExplanation
            ?? 'Recommendations are derived from incident context, customer history, and operational signals.';

        return new AIWorkbenchDTO(
            incidentId: $incident->id,
            scenario: $scenario['key'],
            scenarioLabel: $scenario['label'],
            customerReplies: $this->customerReplies($incident, $response, $context, $scenario['key'], $baseReply),
            internalNote: $this->internalNote($response, $context, $confidenceLevel, $confidenceScore),
            checklist: $this->checklist($response, $context),
            workflowSuggestions: $this->workflowSuggestions($incident, $response, $context),
            confidenceLevel: $confidenceLevel,
            confidenceScore: $confidenceScore,
            confidenceExplanation: $explanation,
            providerName: $response->providerName,
            generatedAt: now(),
        );
    }

    public function buildFromBundle(Incident $incident, AIIncidentBundle $bundle): AIWorkbenchDTO
    {
        return $this->build($incident, $bundle->response, $bundle->context);
    }

    /**
     * @return array{key: string, label: string}
     */
    private function detectScenario(Incident $incident, AIResponseDTO $response, AIContextDTO $context): array
    {
        if ($context->serialMissing || $this->waitingReason($incident) === WaitingReason::SerialNumber) {
            return ['key' => 'waiting_for_serial', 'label' => 'Waiting for serial'];
        }

        if ($context->isWarrantyExpired()) {
            return ['key' => 'warranty_expired', 'label' => 'Warranty expired'];
        }

        if ($this->waitingReason($incident) === WaitingReason::Payment) {
            return ['key' => 'payment_reminder', 'label' => 'Payment reminder'];
        }

        if ($this->waitingReason($incident) === WaitingReason::DevicePickup) {
            return ['key' => 'pickup_scheduled', 'label' => 'Pickup scheduled'];
        }

        if ($incident->status === IncidentStatus::Resolved) {
            return ['key' => 'ready_for_dispatch', 'label' => 'Ready for dispatch'];
        }

        if ($incident->status === IncidentStatus::Closed) {
            return ['key' => 'repair_completed', 'label' => 'Repair completed'];
        }

        if ($incident->status === IncidentStatus::InProgress && $context->waitingState === null) {
            return ['key' => 'device_received', 'label' => 'Device received'];
        }

        if ($context->waitingState !== null) {
            return [
                'key' => 'waiting_for_customer',
                'label' => 'Waiting for '.$context->waitingState['reason_label'],
            ];
        }

        return ['key' => 'general_update', 'label' => 'Status update'];
    }

    /**
     * @return list<array{key: string, channel: string, channel_label: string, content: string, confidence: string, confidence_score: int, explanation: string}>
     */
    private function customerReplies(
        Incident $incident,
        AIResponseDTO $response,
        AIContextDTO $context,
        string $scenario,
        string $baseReply,
    ): array {
        $reference = $context->incidentReference;
        $name = $context->customerName ?? 'Customer';
        $whatsapp = $this->replyForChannel($scenario, 'whatsapp', $name, $reference, $context, $baseReply);
        $email = $this->replyForChannel($scenario, 'email', $name, $reference, $context, $baseReply);
        $internal = $this->replyForChannel($scenario, 'internal_note', $name, $reference, $context, $baseReply);

        return [
            $this->replyArtifact('reply_whatsapp', 'whatsapp', 'WhatsApp', $whatsapp, $response, 'whatsapp'),
            $this->replyArtifact('reply_email', 'email', 'Email', $email, $response, 'email'),
            $this->replyArtifact('reply_internal_note', 'internal_note', 'Internal Note', $internal, $response, 'internal_note'),
        ];
    }

    /**
     * @return array{content: string, confidence: string, confidence_score: int, explanation: string}
     */
    private function internalNote(
        AIResponseDTO $response,
        AIContextDTO $context,
        AIConfidenceLevel $confidenceLevel,
        int $confidenceScore,
    ): array {
        $lines = [];

        if ($context->customerIntelligence->repeatIssueDetected) {
            $lines[] = 'Customer has previous repair history.';
            $lines[] = 'Recommend inspecting previous technician notes before proceeding.';
        }

        if ($context->serialMissing) {
            $lines[] = 'Serial number is missing or pending verification.';
        }

        if ($context->isWarrantyExpired()) {
            $lines[] = 'Warranty appears expired; confirm chargeable repair expectations.';
        }

        if ($context->operationalIntelligence->slaState !== 'Within SLA') {
            $lines[] = 'SLA state: '.$context->operationalIntelligence->slaState.'. Prioritize follow-up.';
        }

        if ($context->deviceIntelligence->previousRepairsOnSerial > 0) {
            $lines[] = 'Serial has '.$context->deviceIntelligence->previousRepairsOnSerial.' prior repair(s).';
        }

        if ($lines === []) {
            $lines[] = 'Review incident details and confirm next operational step with the customer.';
        }

        $primaryAction = $response->suggestedNextActions[0]->title ?? 'Review incident details';

        return [
            'content' => implode("\n", $lines),
            'confidence' => $confidenceLevel->value,
            'confidence_score' => $confidenceScore,
            'explanation' => $this->provider->explainRecommendation($context, $primaryAction),
        ];
    }

    /**
     * @return list<array{key: string, label: string, explanation: string}>
     */
    private function checklist(AIResponseDTO $response, AIContextDTO $context): array
    {
        $items = [];

        if ($context->serialMissing) {
            $items[] = [
                'key' => 'verify_serial',
                'label' => 'Verify serial number',
                'explanation' => 'Serial validation is required before warranty or repair decisions.',
            ];
        }

        $items[] = [
            'key' => 'verify_warranty',
            'label' => 'Verify warranty',
            'explanation' => 'Confirm current warranty status against order enrichment.',
        ];

        if ($context->customerIntelligence->repeatIssueDetected || $context->deviceIntelligence->previousRepairsOnSerial > 0) {
            $items[] = [
                'key' => 'check_previous_repairs',
                'label' => 'Check previous repairs',
                'explanation' => 'Prior repair history may indicate recurring failure patterns.',
            ];
        }

        $items[] = [
            'key' => 'confirm_accessories',
            'label' => 'Confirm accessories received',
            'explanation' => 'Missing accessories can delay diagnosis and repair.',
        ];

        $items[] = [
            'key' => 'run_diagnostics',
            'label' => 'Run diagnostics',
            'explanation' => 'Baseline diagnostics support accurate repair planning.',
        ];

        $items[] = [
            'key' => 'update_customer',
            'label' => 'Update customer',
            'explanation' => 'Proactive communication reduces escalation risk.',
        ];

        return $items;
    }

    /**
     * @return list<array{key: string, label: string, description: string, confidence: string, confidence_score: int, explanation: string}>
     */
    private function workflowSuggestions(
        Incident $incident,
        AIResponseDTO $response,
        AIContextDTO $context,
    ): array {
        $suggestions = [];

        if ($incident->assigned_to_user_id === null) {
            $suggestions[] = $this->workflowArtifact(
                'assign_engineer',
                'Assign Engineer',
                'Case is unassigned. Allocate an engineer to progress the repair.',
                $response,
                $context,
                'Assign Engineer',
            );
        }

        if ($context->serialMissing) {
            $suggestions[] = $this->workflowArtifact(
                'request_serial',
                'Request Serial',
                'Collect the device serial number before continuing validation.',
                $response,
                $context,
                'Request serial number',
            );
        }

        if ($context->isWarrantyExpired()) {
            $suggestions[] = $this->workflowArtifact(
                'send_estimate',
                'Send Estimate',
                'Share a paid repair estimate because warranty coverage is unavailable.',
                $response,
                $context,
                'Inform customer warranty has expired',
            );
        }

        if ($this->waitingReason($incident) === WaitingReason::Payment) {
            $suggestions[] = $this->workflowArtifact(
                'request_payment',
                'Request Payment',
                'Payment is pending. Follow up with the customer for settlement.',
                $response,
                $context,
                'Follow up on waiting state',
            );
        }

        if ($this->waitingReason($incident) === WaitingReason::DevicePickup) {
            $suggestions[] = $this->workflowArtifact(
                'schedule_pickup',
                'Schedule Pickup',
                'Coordinate device pickup with the customer.',
                $response,
                $context,
                'Follow up on waiting state',
            );
        }

        if (in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true)) {
            $suggestions[] = $this->workflowArtifact(
                'close_incident',
                'Close Incident',
                'Repair work appears complete. Confirm closure after final customer update.',
                $response,
                $context,
                'Review incident details',
            );
        }

        if ($suggestions === []) {
            $primary = $response->suggestedNextActions[0] ?? null;
            $suggestions[] = $this->workflowArtifact(
                'review_case',
                $primary?->title ?? 'Review Case',
                $primary?->description ?? 'Review incident details and confirm the next step.',
                $response,
                $context,
                $primary?->title ?? 'Review incident details',
            );
        }

        return $suggestions;
    }

    private function replyForChannel(
        string $scenario,
        string $channel,
        string $name,
        string $reference,
        AIContextDTO $context,
        string $baseReply,
    ): string {
        $message = match ($scenario) {
            'waiting_for_serial' => 'Hello '.$name.', we are waiting for your device serial number to continue service case '.$reference.'. Please share the serial number when available.',
            'warranty_expired' => 'Hello '.$name.', regarding case '.$reference.', our records indicate the device warranty has expired. Our team will share repair options and estimate details shortly.',
            'device_received' => 'Hello '.$name.', we have received your device for case '.$reference.'. Diagnostics are in progress and we will update you on the next step.',
            'repair_completed' => 'Hello '.$name.', repair work for case '.$reference.' is complete. Please let us know if you need any additional assistance.',
            'payment_reminder' => 'Hello '.$name.', this is a reminder that payment is pending for service case '.$reference.'. Please complete payment so we can continue processing.',
            'pickup_scheduled' => 'Hello '.$name.', pickup has been scheduled for service case '.$reference.'. Our team will confirm the pickup slot with you shortly.',
            'ready_for_dispatch' => 'Hello '.$name.', your device for case '.$reference.' is ready for dispatch. We will share dispatch details shortly.',
            default => $baseReply,
        };

        return match ($channel) {
            'email' => "Subject: Update on service case {$reference}\n\n{$message}\n\nRegards,\nRadium Service Team",
            'internal_note' => "Internal note for {$reference}:\n- Scenario: ".Str::headline(str_replace('_', ' ', $scenario))."\n- Customer: {$name}\n- Next step: ".$this->waitingSummary($context),
            default => $message,
        };
    }

    /**
     * @return array{key: string, channel: string, channel_label: string, content: string, confidence: string, confidence_score: int, explanation: string}
     */
    private function replyArtifact(
        string $key,
        string $channel,
        string $channelLabel,
        string $content,
        AIResponseDTO $response,
        string $explanationSeed,
    ): array {
        return [
            'key' => $key,
            'channel' => $channel,
            'channel_label' => $channelLabel,
            'content' => $content,
            'confidence' => $response->confidenceLevel->value,
            'confidence_score' => $response->confidenceScore,
            'explanation' => $response->recommendationExplanation
                ?? 'Reply tailored for '.$channelLabel.' using the current incident scenario.',
        ];
    }

    /**
     * @return array{key: string, label: string, description: string, confidence: string, confidence_score: int, explanation: string}
     */
    private function workflowArtifact(
        string $key,
        string $label,
        string $description,
        AIResponseDTO $response,
        AIContextDTO $context,
        string $explanationSeed,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'confidence' => $response->confidenceLevel->value,
            'confidence_score' => $response->confidenceScore,
            'explanation' => $this->provider->explainRecommendation($context, $explanationSeed),
        ];
    }

    private function waitingReason(Incident $incident): ?WaitingReason
    {
        return $incident->activeWaitingState?->waiting_reason;
    }

    private function waitingSummary(AIContextDTO $context): string
    {
        if ($context->serialMissing) {
            return 'Waiting for serial number';
        }

        if ($context->waitingState !== null) {
            return 'Waiting for '.($context->waitingState['reason_label'] ?? 'customer input');
        }

        return 'Continue standard repair workflow';
    }
}
