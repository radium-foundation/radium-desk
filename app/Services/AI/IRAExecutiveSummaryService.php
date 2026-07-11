<?php

namespace App\Services\AI;

use App\Data\AI\AIContextBuildSnapshot;
use App\Data\AI\AIIncidentBundle;
use App\Data\AI\AIResponseDTO;
use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Operations\OperationsInsightDTO;
use App\Data\SerialInsight;
use App\Enums\Operations\OperationsInsightCategory;
use App\Enums\SerialInsightConfidence;
use App\Enums\SerialInsightStatus;
use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Services\SerialValidation\SerialInsightService;
use App\Enums\ServiceCaseSlaStatus;
use App\Support\DeviceModelFormatter;
use App\Support\AppDateFormatter;
use Illuminate\Support\Str;

class IRAExecutiveSummaryService
{
    public function __construct(
        private readonly SerialInsightService $serialInsightService,
    ) {}

    /**
     * @param  list<OperationsInsightDTO>  $operationsAdvisorInsights
     */
    public function buildFromBundle(
        Incident $incident,
        AIIncidentBundle $bundle,
        ?AIContextBuildSnapshot $snapshot = null,
        array $operationsAdvisorInsights = [],
    ): IRAExecutiveSummaryDTO {
        return $this->build(
            incident: $incident,
            response: $bundle->response,
            context: $bundle->context,
            customerSummary: $snapshot?->customerSummary ?? $bundle->context->customerSummary,
            operationsAdvisorInsights: $operationsAdvisorInsights,
        );
    }

    /**
     * @param  array<string, int>  $customerSummary
     * @param  list<OperationsInsightDTO>  $operationsAdvisorInsights
     */
    public function build(
        Incident $incident,
        AIResponseDTO $response,
        \App\Data\AI\AIContextDTO $context,
        array $customerSummary,
        array $operationsAdvisorInsights = [],
    ): IRAExecutiveSummaryDTO {
        $serialInsight = $this->resolveSerialInsight($incident);

        $executiveSummary = $this->buildExecutiveSummary(
            $incident,
            $response,
            $context,
            $customerSummary,
            $serialInsight,
        );

        return new IRAExecutiveSummaryDTO(
            executiveSummary: array_slice($executiveSummary, 0, 4),
            opinion: $this->buildOpinion($response, $context, $operationsAdvisorInsights, $serialInsight),
            recommendation: $this->buildRecommendation($response, $context, $operationsAdvisorInsights, $serialInsight),
            serialInsight: $serialInsight,
        );
    }

    private function resolveSerialInsight(Incident $incident): ?SerialInsight
    {
        $order = $incident->order;

        if (! $order instanceof Order) {
            return null;
        }

        return $this->serialInsightService->analyze($order);
    }

    /**
     * @param  array<string, int>  $customerSummary
     * @return list<string>
     */
    private function buildExecutiveSummary(
        Incident $incident,
        AIResponseDTO $response,
        \App\Data\AI\AIContextDTO $context,
        array $customerSummary,
        ?SerialInsight $serialInsight = null,
    ): array {
        $lines = [];
        $model = DeviceModelFormatter::shortDisplay($context->deviceModel) ?: 'device';
        $openCases = max(1, (int) ($customerSummary['open_cases'] ?? 0));
        $repairLabel = $openCases === 1 ? 'one active repair' : "{$openCases} active repairs";
        $hasSerialConcern = $this->hasSerialConcern($context, $serialInsight);

        if ($hasSerialConcern) {
            $lines[] = $this->serialExecutiveSummaryLine($context, $serialInsight, $model, $repairLabel);
        } else {
            $lines[] = "Customer purchased {$this->withArticle($model)} and currently has {$repairLabel}.";
        }

        if ($context->hasCustomerJourney()) {
            foreach ($this->customerJourneyLines($context) as $journeyLine) {
                $lines[] = $journeyLine;
            }
        } else {
            $waitingLifecycleLine = $context->hasSupportAppointment()
                ? null
                : $this->customerWaitingLifecycleLine($context);

            if ($waitingLifecycleLine !== null) {
                $lines[] = $waitingLifecycleLine;
            }

            foreach ($this->supportAppointmentLines($incident, $context) as $appointmentLine) {
                $lines[] = $appointmentLine;
            }
        }

        if (! $hasSerialConcern) {
            $warrantyLine = $this->warrantySummaryLine($context, $response);

            if ($warrantyLine !== null) {
                $lines[] = $warrantyLine;
            }
        }

        $slaLine = $this->slaSummaryLine($incident, $context, $response);

        if ($slaLine !== null) {
            $lines[] = $slaLine;
        }

        $repeatContactLine = trim((string) ($context->operationalIntelligence->repeatContactSummary ?? ''));

        if ($repeatContactLine !== '' && ! $this->lineAlreadyCoversRepeatContact($lines, $repeatContactLine)) {
            $lines[] = $repeatContactLine;
        }

        if ($lines === []) {
            $lines[] = 'Review the current service case context before contacting the customer.';
        }

        return $this->deduplicateLines($lines);
    }

    /**
     * @param  list<OperationsInsightDTO>  $operationsAdvisorInsights
     */
    private function buildOpinion(
        AIResponseDTO $response,
        \App\Data\AI\AIContextDTO $context,
        array $operationsAdvisorInsights,
        ?SerialInsight $serialInsight = null,
    ): string {
        $lifecycleHistory = is_array($context->waitingState)
            ? ($context->waitingState['lifecycle_history'] ?? null)
            : null;

        if (is_array($lifecycleHistory) && ($lifecycleHistory['auto_closed'] ?? false)) {
            $reasonLabel = $lifecycleHistory['resolution_reason_label'] ?? 'Customer not responding';

            return "This case was auto-closed after the customer did not respond to a follow-up reminder ({$reasonLabel}).";
        }

        if ($serialInsight?->status === SerialInsightStatus::Suspicious) {
            return 'The current serial number looks incorrect and should be confirmed with the customer before proceeding.';
        }

        if ($serialInsight?->status === SerialInsightStatus::Warning) {
            return 'The current serial number needs verification before warranty or repair work can proceed safely.';
        }

        if ($context->serialMissing || $serialInsight?->status === SerialInsightStatus::Missing) {
            return 'This case is blocked until the device serial number is received from the customer.';
        }

        if ($context->operationalIntelligence->repeatContactHighUrgency) {
            return 'The customer has tried contacting multiple times and needs a prioritized callback.';
        }

        $journeyOpinion = $this->customerJourneyOpinion($context);

        if ($journeyOpinion !== null) {
            return $journeyOpinion;
        }

        if ($context->hasCompletedSupportAppointment()) {
            return $this->completedSupportAppointmentOpinion();
        }

        if ($context->hasActiveSupportAppointment()) {
            return $this->activeSupportAppointmentOpinion($context);
        }

        if ($this->hasCancelledSupportAppointment($context)) {
            return $this->cancelledSupportAppointmentOpinion($context);
        }

        if ($this->hasMissedSupportAppointment($context)) {
            return 'Customer missed a scheduled support appointment; follow up to rebook support.';
        }

        if ($context->customerIntelligence->repeatIssueDetected) {
            return 'This customer has experienced repeat failures and deserves proactive handling.';
        }

        if ($context->isWarrantyExpired()) {
            return 'Customer expectations should be managed before repair begins.';
        }

        if ($this->hasHighSlaRisk($context, $operationsAdvisorInsights)) {
            return 'This incident requires immediate attention to avoid further delay.';
        }

        if ($context->waitingState !== null && isset($context->waitingState['reason_label'])) {
            $reason = $context->waitingState['reason_label'] ?? 'customer input';

            return "Service progress depends on {$reason}; keep the customer informed while waiting.";
        }

        $primaryInsight = $operationsAdvisorInsights[0] ?? null;

        if ($primaryInsight !== null) {
            return $this->firstSentence($primaryInsight->recommendation);
        }

        $explanation = trim((string) ($response->recommendationExplanation ?? ''));

        return $explanation !== ''
            ? $this->firstSentence($explanation)
            : 'This case can proceed with standard service handling once the next dependency is cleared.';
    }

    /**
     * @param  list<OperationsInsightDTO>  $operationsAdvisorInsights
     */
    private function buildRecommendation(
        AIResponseDTO $response,
        \App\Data\AI\AIContextDTO $context,
        array $operationsAdvisorInsights,
        ?SerialInsight $serialInsight = null,
    ): string {
        if (in_array($serialInsight?->status, [
            SerialInsightStatus::Suspicious,
            SerialInsightStatus::Warning,
        ], true)) {
            return 'Request the correct serial number from the customer before closing this case.';
        }

        if ($context->serialMissing || $serialInsight?->status === SerialInsightStatus::Missing) {
            return 'Request the serial number from the customer immediately.';
        }

        if ($context->isWarrantyExpired()) {
            return 'Confirm chargeable repair expectations with the customer before engineering begins work.';
        }

        if ($context->operationalIntelligence->repeatContactHighUrgency) {
            return 'Prioritize callback now and update the customer immediately.';
        }

        $journeyRecommendation = $this->customerJourneyRecommendation($context);

        if ($journeyRecommendation !== null) {
            return $journeyRecommendation;
        }

        if ($context->hasCompletedSupportAppointment()) {
            return $this->completedSupportAppointmentRecommendation();
        }

        if ($context->hasActiveSupportAppointment()) {
            return $this->activeSupportAppointmentRecommendation($context);
        }

        if ($this->hasCancelledSupportAppointment($context)) {
            return $this->cancelledSupportAppointmentRecommendation($context);
        }

        if ($this->hasMissedSupportAppointment($context)) {
            return 'Contact the customer to reschedule the missed support appointment.';
        }

        if ($context->customerIntelligence->repeatIssueDetected) {
            return 'Review prior technician notes and communicate a proactive repair plan.';
        }

        if ($this->hasHighSlaRisk($context, $operationsAdvisorInsights)) {
            return 'Prioritize resolution and send the customer an immediate status update.';
        }

        $primaryAction = $response->suggestedNextActions[0] ?? null;

        if ($primaryAction !== null && ! $this->isCustomerReminderAction($primaryAction->title, $primaryAction->description)) {
            $description = trim($primaryAction->description);

            return $description !== ''
                ? $this->firstSentence($primaryAction->title.': '.$description)
                : $this->firstSentence($primaryAction->title);
        }

        $advisorRecommendation = $operationsAdvisorInsights[0]?->recommendation;

        return filled($advisorRecommendation)
            ? $this->firstSentence($advisorRecommendation)
            : 'Review incident details and contact the customer with the next update.';
    }

    private function serialExecutiveSummaryLine(
        \App\Data\AI\AIContextDTO $context,
        ?SerialInsight $serialInsight,
        string $model,
        string $repairLabel,
    ): string {
        $purchaseContext = "Customer purchased {$this->withArticle($model)} and currently has {$repairLabel}.";

        if ($serialInsight === null) {
            return 'Serial number needs verification. '.$purchaseContext;
        }

        $confidenceLabel = Str::lower($serialInsight->confidence->label());

        if (in_array($serialInsight->status, [
            SerialInsightStatus::Suspicious,
            SerialInsightStatus::Warning,
        ], true)) {
            return "Serial number needs verification. {$purchaseContext} Current serial confidence is {$confidenceLabel}.";
        }

        if ($context->serialMissing || $serialInsight->status === SerialInsightStatus::Missing) {
            return 'The device serial number is still missing, causing service delay.';
        }

        return $purchaseContext;
    }

    private function hasSerialConcern(
        \App\Data\AI\AIContextDTO $context,
        ?SerialInsight $serialInsight,
    ): bool {
        if ($context->serialMissing || $serialInsight?->status === SerialInsightStatus::Missing) {
            return true;
        }

        return in_array($serialInsight?->status, [
            SerialInsightStatus::Suspicious,
            SerialInsightStatus::Warning,
        ], true);
    }

    /**
     * @param  list<string>  $lines
     */
    private function lineAlreadyCoversRepeatContact(array $lines, string $repeatContactLine): bool
    {
        $normalizedRepeat = Str::lower($repeatContactLine);

        foreach ($lines as $line) {
            $normalizedLine = Str::lower($line);

            if (Str::contains($normalizedLine, 'callback')
                && Str::contains($normalizedRepeat, 'callback')) {
                return true;
            }

            if (Str::contains($normalizedLine, 'contacted')
                && Str::contains($normalizedRepeat, 'contacted')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function deduplicateLines(array $lines): array
    {
        $unique = [];
        $seen = [];

        foreach ($lines as $line) {
            $normalized = Str::lower(trim($line));

            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $unique[] = $line;
        }

        return $unique;
    }

    private function firstSentence(string $text): string
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return '';
        }

        $sentence = preg_split('/(?<=[.!?])\s+/', $trimmed, 2)[0] ?? $trimmed;

        return rtrim($sentence, '.').'.';
    }

    private function customerWaitingLifecycleLine(\App\Data\AI\AIContextDTO $context): ?string
    {
        $waitingState = $context->waitingState;

        if (! is_array($waitingState)) {
            return null;
        }

        $lifecycleHistory = $waitingState['lifecycle_history'] ?? null;

        if (is_array($lifecycleHistory) && ($lifecycleHistory['auto_closed'] ?? false)) {
            $followupSentAt = $lifecycleHistory['customer_followup_sent_at'] ?? null;
            $followupLabel = $followupSentAt instanceof \Illuminate\Support\Carbon
                ? $followupSentAt->toDayDateTimeString()
                : 'an earlier time';

            return "Case was auto-closed after a follow-up reminder sent at {$followupLabel}; customer did not respond within 24 hours.";
        }

        if (($waitingState['customer_followup_sent_at'] ?? null) instanceof \Illuminate\Support\Carbon) {
            return 'A customer waiting follow-up reminder was already sent; await response before closing manually.';
        }

        if (($waitingState['customer_waiting_since'] ?? null) instanceof \Illuminate\Support\Carbon) {
            $reason = $waitingState['reason_label'] ?? ($lifecycleHistory['waiting_reason_label'] ?? 'customer input');

            return "Waiting for {$reason} since ".$waitingState['customer_waiting_since']->toDayDateTimeString().'.';
        }

        if (is_array($lifecycleHistory) && ($lifecycleHistory['customer_waiting_since'] ?? null) instanceof \Illuminate\Support\Carbon) {
            $reason = $lifecycleHistory['waiting_reason_label'] ?? 'customer input';

            return "Previously waited for {$reason} since ".$lifecycleHistory['customer_waiting_since']->toDayDateTimeString().'.';
        }

        return null;
    }

    private function warrantySummaryLine(
        \App\Data\AI\AIContextDTO $context,
        AIResponseDTO $response,
    ): ?string {
        if ($context->isWarrantyExpired()) {
            return 'Warranty appears expired; confirm chargeable repair expectations.';
        }

        if ($this->warrantyNeedsVerification($context, $response)) {
            return 'Warranty cannot yet be verified.';
        }

        $status = Str::lower($context->warrantyStatus);

        if (Str::contains($status, 'active') || Str::contains($status, 'valid')) {
            return 'Warranty appears active.';
        }

        return null;
    }

    private function slaSummaryLine(
        Incident $incident,
        \App\Data\AI\AIContextDTO $context,
        AIResponseDTO $response,
    ): ?string {
        $slaStatus = $incident->slaStatus();
        $slaState = Str::lower($context->operationalIntelligence->slaState);

        if ($slaStatus === ServiceCaseSlaStatus::Overdue
            || Str::contains($slaState, 'overdue')
            || ($incident->high_priority && $slaStatus === ServiceCaseSlaStatus::Warning)) {
            return 'This case is already beyond SLA and should be prioritized.';
        }

        if ($slaStatus === ServiceCaseSlaStatus::Warning || Str::contains($slaState, 'warning')) {
            return 'This case is approaching SLA limits and needs timely follow-up.';
        }

        foreach ($response->riskIndicators as $indicator) {
            if (Str::contains(Str::lower($indicator->label), 'sla')) {
                return 'This case is already beyond SLA and should be prioritized.';
            }
        }

        return null;
    }

    /**
     * @param  list<OperationsInsightDTO>  $operationsAdvisorInsights
     */
    private function hasHighSlaRisk(
        \App\Data\AI\AIContextDTO $context,
        array $operationsAdvisorInsights,
    ): bool {
        $slaState = Str::lower($context->operationalIntelligence->slaState);

        if (Str::contains($slaState, 'overdue') || Str::contains($slaState, 'warning')) {
            return true;
        }

        foreach ($operationsAdvisorInsights as $insight) {
            if ($insight->category === OperationsInsightCategory::SlaRisk) {
                return true;
            }
        }

        return false;
    }

    private function warrantyNeedsVerification(
        \App\Data\AI\AIContextDTO $context,
        AIResponseDTO $response,
    ): bool {
        if ($context->serialMissing) {
            return true;
        }

        $status = Str::lower($context->warrantyStatus.' '.$response->warrantyStatus);

        return Str::contains($status, ['not available', 'unknown', 'unavailable', 'pending']);
    }

    private function withArticle(string $model): string
    {
        $first = Str::lower(Str::substr($model, 0, 1));

        $article = in_array($first, ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';

        return "{$article} {$model}";
    }

    /**
     * @return list<string>
     */
    private function supportAppointmentLines(Incident $incident, \App\Data\AI\AIContextDTO $context): array
    {
        $appointment = $context->supportAppointment;

        if ($appointment === null) {
            return [];
        }

        if ($context->hasCompletedSupportAppointment()) {
            return $this->completedSupportAppointmentLines($incident, $context);
        }

        if ($context->hasActiveSupportAppointment()) {
            $line = $this->activeSupportAppointmentSummaryLine($context);

            return $line !== null ? [$line] : [];
        }

        if ($this->hasCancelledSupportAppointment($context)) {
            $line = $this->cancelledSupportAppointmentSummaryLine($context);

            return $line !== null ? [$line] : [];
        }

        if ($this->hasMissedSupportAppointment($context)) {
            $scheduleDetails = $this->appointmentScheduleDetails($appointment);
            $line = "Customer missed scheduled support on {$scheduleDetails}.";

            return [$line];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function completedSupportAppointmentLines(Incident $incident, \App\Data\AI\AIContextDTO $context): array
    {
        $appointment = $context->supportAppointment;
        $lines = [
            'Customer booked support on '.$this->appointmentScheduleDetails($appointment).'.',
            'Support completed.',
        ];

        if (! $incident->isActive()) {
            $lines[] = 'Service case is now closed.';
        }

        return $lines;
    }

    private function activeSupportAppointmentSummaryLine(\App\Data\AI\AIContextDTO $context): ?string
    {
        $appointment = $context->supportAppointment;

        if ($appointment === null) {
            return null;
        }

        return 'Customer has a scheduled support appointment on '.$this->appointmentScheduleDetails($appointment).'.';
    }

    private function cancelledSupportAppointmentSummaryLine(\App\Data\AI\AIContextDTO $context): ?string
    {
        $appointment = $context->supportAppointment;

        if ($appointment === null) {
            return null;
        }

        return 'Customer cancelled the support appointment scheduled for '.$this->appointmentScheduleDetails($appointment).'.';
    }

    /**
     * @param  array<string, mixed>  $appointment
     */
    private function appointmentScheduleDetails(array $appointment): string
    {
        $dateLabel = AppDateFormatter::format($appointment['preferred_date'], 'd M');
        $slotLabel = trim((string) ($appointment['time_slot_label'] ?? ''));

        return $slotLabel !== ''
            ? "{$dateLabel} ({$slotLabel})"
            : $dateLabel;
    }

    private function completedSupportAppointmentOpinion(): string
    {
        return 'Customer has already received scheduled support. No further appointment reminders are required.';
    }

    private function completedSupportAppointmentRecommendation(): string
    {
        return 'Review support outcome. Reopen only if customer reports the issue persists.';
    }

    private function activeSupportAppointmentOpinion(\App\Data\AI\AIContextDTO $context): string
    {
        return 'Await scheduled support.';
    }

    private function activeSupportAppointmentRecommendation(\App\Data\AI\AIContextDTO $context): string
    {
        $assigneeName = trim((string) ($context->supportAppointment['assignee_name'] ?? ''));

        if ($assigneeName !== '') {
            return "{$assigneeName} should contact the customer as scheduled.";
        }

        return 'Assigned agent should contact the customer as scheduled.';
    }

    private function cancelledSupportAppointmentOpinion(\App\Data\AI\AIContextDTO $context): string
    {
        return 'The scheduled support appointment was cancelled; confirm whether the customer still needs assistance.';
    }

    private function cancelledSupportAppointmentRecommendation(\App\Data\AI\AIContextDTO $context): string
    {
        return 'Confirm whether the customer still needs support and offer to rebook if required.';
    }

    private function hasCancelledSupportAppointment(\App\Data\AI\AIContextDTO $context): bool
    {
        $status = $context->supportAppointment['status'] ?? null;

        return $status === SupportAppointmentStatus::Cancelled;
    }

    private function hasMissedSupportAppointment(\App\Data\AI\AIContextDTO $context): bool
    {
        $status = $context->supportAppointment['status'] ?? null;

        if ($status instanceof SupportAppointmentStatus) {
            return $status->value === 'missed';
        }

        return $status === 'missed';
    }

    private function isCustomerReminderAction(string $title, string $description): bool
    {
        $combined = Str::lower(trim($title.' '.$description));

        return Str::contains($combined, ['reminder', 'follow-up reminder', 'follow up reminder', 'nudge customer']);
    }

    /**
     * @return list<string>
     */
    private function customerJourneyLines(\App\Data\AI\AIContextDTO $context): array
    {
        $journey = $context->customerJourney;

        if ($journey === null || $journey->milestones === []) {
            return [];
        }

        $titles = array_map(
            fn ($milestone) => $milestone->title,
            $journey->milestones,
        );

        return [
            'Customer journey: '.implode(' → ', $titles),
            $journey->confidence->summaryLine(),
        ];
    }

    private function customerJourneyOpinion(\App\Data\AI\AIContextDTO $context): ?string
    {
        $journey = $context->customerJourney;

        if ($journey === null) {
            return null;
        }

        $conclusion = $journey->conclusion;

        return $conclusion->headline.'. '.$conclusion->detail;
    }

    private function customerJourneyRecommendation(\App\Data\AI\AIContextDTO $context): ?string
    {
        $journey = $context->customerJourney;

        if ($journey === null) {
            return null;
        }

        return $this->firstSentence($journey->conclusion->recommendation);
    }
}
