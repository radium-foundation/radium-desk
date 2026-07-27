<?php

namespace App\Services\AI;

use App\Contracts\AI\AIProvider;
use App\Data\AI\AIContextDTO;
use App\Data\AI\AIIncidentBundle;
use App\Data\AI\AIResponseDTO;
use App\Data\AI\AIWorkbenchDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;
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
     * Thin adapter — workbench must already be on the snapshot.
     */
    public function fromSnapshot(CaseIntelligenceSnapshot $snapshot): AIWorkbenchDTO
    {
        return $snapshot->workbench;
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

        if ($incident->status === IncidentStatus::InProgress && ! $this->hasActiveWaitingState($context->waitingState)) {
            return ['key' => 'device_received', 'label' => 'Device received'];
        }

        if ($this->hasActiveWaitingState($context->waitingState)) {
            return [
                'key' => 'waiting_for_customer',
                'label' => 'Waiting for '.$this->activeWaitingReasonLabel($context->waitingState),
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
        $name = $context->customerName ?? 'Customer';

        if ($context->serialMissing) {
            $lines[] = 'Serial pending';
        }

        if ($context->hasSupportAppointment() && ! $context->hasCompletedSupportAppointment()) {
            $lines[] = 'Appointment overdue';
        }

        if ($this->hasActiveWaitingState($context->waitingState)) {
            $lines[] = 'Waiting on customer for '.$this->activeWaitingReasonLabel($context->waitingState);
        }

        if ($context->operationalIntelligence->slaState !== 'Within SLA') {
            $lines[] = 'SLA: '.$context->operationalIntelligence->slaState;
        }

        if ($context->lastPayment !== null) {
            $lines[] = 'Payment received';
        }

        if ($context->customerIntelligence->repeatIssueDetected) {
            $lines[] = 'Repeat repair history — review prior notes';
        }

        $lines = array_values(array_unique(array_filter($lines)));

        if ($lines === []) {
            $lines[] = 'Review case and confirm next step with '.$name;
        }

        $primaryAction = ($response->suggestedNextActions[0] ?? null)?->title
            ?? 'Call customer today';
        $lines[] = $primaryAction;

        if ($context->serialMissing || $this->hasActiveWaitingState($context->waitingState)) {
            $lines[] = 'If unreachable after final reminder, follow closure policy';
        }

        return [
            'content' => implode('. ', array_map(
                static fn (string $line): string => rtrim($line, '.'),
                array_values(array_unique($lines)),
            )).'.',
            'confidence' => $confidenceLevel->value,
            'confidence_score' => $confidenceScore,
            'explanation' => $this->provider->explainRecommendation($context, $primaryAction),
        ];
    }

    /**
     * @return list<array{key: string, label: string, explanation: string, done: bool}>
     */
    private function checklist(AIResponseDTO $response, AIContextDTO $context): array
    {
        $items = [];

        if ($context->lastPayment !== null) {
            $items[] = [
                'key' => 'payment_received',
                'label' => 'Payment received',
                'explanation' => 'Payment is already on record for this case.',
                'done' => true,
            ];
        } elseif ($this->waitingReasonFromContext($context) === WaitingReason::Payment->value
            || str_contains(strtolower((string) ($context->waitingState['waiting_reason'] ?? '')), 'payment')
            || str_contains(strtolower((string) ($context->waitingState['reason_label'] ?? '')), 'payment')) {
            $items[] = [
                'key' => 'collect_payment',
                'label' => 'Collect payment',
                'explanation' => 'Payment is still outstanding.',
                'done' => false,
            ];
        }

        if ($context->serialMissing) {
            $items[] = [
                'key' => 'verify_serial',
                'label' => 'Verify serial number',
                'explanation' => 'Serial validation is required before warranty or repair decisions.',
                'done' => false,
            ];
        }

        $needsCustomerContact = $context->serialMissing
            || $this->hasActiveWaitingState($context->waitingState)
            || ($context->hasSupportAppointment() && ! $context->hasCompletedSupportAppointment())
            || $context->operationalIntelligence->slaState !== 'Within SLA';

        if ($needsCustomerContact) {
            $items[] = [
                'key' => 'contact_customer',
                'label' => 'Contact customer',
                'explanation' => 'Customer outreach is required to unblock this case.',
                'done' => false,
            ];
        }

        if ($context->hasSupportAppointment() && ! $context->hasCompletedSupportAppointment()) {
            $items[] = [
                'key' => 'confirm_appointment',
                'label' => 'Confirm appointment',
                'explanation' => 'Support appointment still needs confirmation or completion.',
                'done' => false,
            ];
        }

        if ($context->isWarrantyExpired()) {
            $items[] = [
                'key' => 'verify_warranty',
                'label' => 'Confirm chargeable repair',
                'explanation' => 'Warranty appears expired; confirm paid-repair expectations.',
                'done' => false,
            ];
        }

        if ($context->customerIntelligence->repeatIssueDetected
            || $context->deviceIntelligence->previousRepairsOnSerial > 0) {
            $items[] = [
                'key' => 'check_previous_repairs',
                'label' => 'Check previous repairs',
                'explanation' => 'Prior repair history may indicate recurring failure patterns.',
                'done' => false,
            ];
        }

        if ($needsCustomerContact || $context->serialMissing) {
            $items[] = [
                'key' => 'close_after_follow_up',
                'label' => 'Close after final follow-up if unreachable',
                'explanation' => 'Follow closure policy after required reminders with no response.',
                'done' => false,
            ];
        }

        if ($items === []) {
            $primary = ($response->suggestedNextActions[0] ?? null)?->title;
            $items[] = [
                'key' => 'complete_next_step',
                'label' => $primary ?? 'Complete next operational step',
                'explanation' => 'No open blockers detected — progress the recommended action.',
                'done' => false,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>|null  $waitingState
     */
    private function waitingReasonFromContext(AIContextDTO $context): ?string
    {
        $waitingState = $context->waitingState;
        if (! is_array($waitingState)) {
            return null;
        }

        $reason = $waitingState['waiting_reason'] ?? null;

        return filled($reason) ? (string) $reason : null;
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
        $firstName = Str::of($name)->trim()->explode(' ')->first() ?: $name;

        $message = match ($scenario) {
            'waiting_for_serial' => 'Hi '.$firstName.",\nYour support appointment is pending because we still need to verify your device serial number. Please reply with the serial number or contact us today so we can complete your service request (".$reference.').',
            'warranty_expired' => 'Hi '.$firstName.",\nRegarding case ".$reference.', our records show the device warranty has expired. Reply today if you would like a paid repair estimate so we can continue.',
            'device_received' => 'Hi '.$firstName.",\nWe have received your device for case ".$reference.'. Diagnostics are in progress and we will update you on the next step.',
            'repair_completed' => 'Hi '.$firstName.",\nRepair work for case ".$reference.' is complete. Please let us know if you need any additional assistance.',
            'payment_reminder' => 'Hi '.$firstName.",\nPayment is still pending for service case ".$reference.'. Please complete payment today so we can continue processing your request.',
            'pickup_scheduled' => 'Hi '.$firstName.",\nPickup has been scheduled for service case ".$reference.'. Our team will confirm the pickup slot with you shortly.',
            'ready_for_dispatch' => 'Hi '.$firstName.",\nYour device for case ".$reference.' is ready for dispatch. We will share dispatch details shortly.',
            'waiting_for_customer' => 'Hi '.$firstName.",\nWe are waiting on your response for service case ".$reference.' ('.$this->waitingSummary($context).'). Please reply today so we can move your request forward.',
            default => filled(trim($baseReply))
                ? $baseReply
                : 'Hi '.$firstName.",\nWe need a quick update from you on service case ".$reference.'. Please reply today so we can complete your request.',
        };

        return match ($channel) {
            'email' => "Subject: Update on service case {$reference}\n\n{$message}\n\nRegards,\nRadium Service Team",
            'internal_note' => $this->compactInternalReplyNote($context, $reference, $name, $scenario),
            default => $message,
        };
    }

    private function compactInternalReplyNote(
        AIContextDTO $context,
        string $reference,
        string $name,
        string $scenario,
    ): string {
        $bits = ["Case {$reference} ({$name})"];

        if ($context->serialMissing) {
            $bits[] = 'Serial pending';
        }
        if ($context->hasSupportAppointment() && ! $context->hasCompletedSupportAppointment()) {
            $bits[] = 'Appointment overdue';
        }
        if ($context->operationalIntelligence->slaState !== 'Within SLA') {
            $bits[] = 'SLA '.$context->operationalIntelligence->slaState;
        }

        $bits[] = 'Next: '.Str::headline(str_replace('_', ' ', $scenario));
        $bits[] = 'Call customer today. If unreachable after final reminder, follow closure policy';

        return implode('. ', $bits).'.';
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

    /**
     * True only for a CURRENT waiting state.
     * lifecycle_history alone is historical evidence, not an active wait.
     *
     * @param  array<string, mixed>|null  $waitingState
     */
    private function hasActiveWaitingState(?array $waitingState): bool
    {
        if ($waitingState === null || $waitingState === []) {
            return false;
        }

        if (filled($waitingState['reason_label'] ?? null)) {
            return true;
        }

        if (filled($waitingState['waiting_reason'] ?? null)) {
            return true;
        }

        // Active-card metadata (present on customer360Card, absent on lifecycleOnlyCard).
        if (($waitingState['started_at'] ?? null) !== null
            || ($waitingState['customer_waiting_since'] ?? null) !== null) {
            return true;
        }

        return false;
    }

    /**
     * Label for an active waiting state only. Never reads lifecycle_history.
     *
     * @param  array<string, mixed>  $waitingState
     */
    private function activeWaitingReasonLabel(array $waitingState): string
    {
        $reasonLabel = $waitingState['reason_label'] ?? null;
        if (filled($reasonLabel)) {
            return (string) $reasonLabel;
        }

        $waitingReason = $waitingState['waiting_reason'] ?? null;
        if (filled($waitingReason)) {
            return is_string($waitingReason)
                ? $waitingReason
                : (string) $waitingReason;
        }

        return 'customer';
    }

    private function waitingSummary(AIContextDTO $context): string
    {
        if ($context->serialMissing) {
            return 'Waiting for serial number';
        }

        if ($this->hasActiveWaitingState($context->waitingState)) {
            /** @var array<string, mixed> $waitingState */
            $waitingState = $context->waitingState;

            return 'Waiting for '.$this->activeWaitingReasonLabel($waitingState);
        }

        return 'Continue standard repair workflow';
    }
}
