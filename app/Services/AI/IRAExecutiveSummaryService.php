<?php

namespace App\Services\AI;

use App\Data\AI\AIContextBuildSnapshot;
use App\Data\AI\AIIncidentBundle;
use App\Data\AI\AIResponseDTO;
use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Operations\OperationsInsightDTO;
use App\Enums\Operations\OperationsInsightCategory;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\Incident;
use App\Support\DeviceModelFormatter;
use Illuminate\Support\Str;

class IRAExecutiveSummaryService
{
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
        $executiveSummary = $this->buildExecutiveSummary(
            $incident,
            $response,
            $context,
            $customerSummary,
        );

        return new IRAExecutiveSummaryDTO(
            executiveSummary: array_slice($executiveSummary, 0, 4),
            opinion: $this->buildOpinion($response, $context, $operationsAdvisorInsights),
            recommendation: $this->buildRecommendation($response, $context, $operationsAdvisorInsights),
        );
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
    ): array {
        $lines = [];
        $model = DeviceModelFormatter::shortDisplay($context->deviceModel) ?: 'device';
        $openCases = max(1, (int) ($customerSummary['open_cases'] ?? 0));
        $repairLabel = $openCases === 1 ? 'one active repair' : "{$openCases} active repairs";

        $lines[] = "Customer purchased {$this->withArticle($model)} and currently has {$repairLabel}.";

        if ($context->serialMissing) {
            $lines[] = 'The device serial number is still missing, causing service delay.';
        }

        $warrantyLine = $this->warrantySummaryLine($context, $response);

        if ($warrantyLine !== null) {
            $lines[] = $warrantyLine;
        }

        $slaLine = $this->slaSummaryLine($incident, $context, $response);

        if ($slaLine !== null) {
            $lines[] = $slaLine;
        }

        if ($lines === []) {
            $lines[] = 'Review the current service case context before contacting the customer.';
        }

        return $lines;
    }

    /**
     * @param  list<OperationsInsightDTO>  $operationsAdvisorInsights
     */
    private function buildOpinion(
        AIResponseDTO $response,
        \App\Data\AI\AIContextDTO $context,
        array $operationsAdvisorInsights,
    ): string {
        if ($context->serialMissing) {
            return 'This appears to be a straightforward serial-number pending case. Obtaining the serial should unblock warranty validation and allow engineering to proceed.';
        }

        if ($context->customerIntelligence->repeatIssueDetected) {
            $summary = trim((string) ($context->customerIntelligence->repeatIssueSummary ?? ''));

            return $summary !== ''
                ? "This customer has experienced repeat failures and deserves proactive handling. {$summary}"
                : 'This customer has experienced repeat failures and deserves proactive handling.';
        }

        if ($context->isWarrantyExpired()) {
            return 'Customer expectations should be managed before repair begins.';
        }

        if ($this->hasHighSlaRisk($context, $operationsAdvisorInsights)) {
            return 'This incident requires immediate attention to avoid further delay.';
        }

        if ($context->waitingState !== null) {
            $reason = $context->waitingState['reason_label'] ?? 'customer input';

            return "Service progress depends on {$reason}; keep the customer informed while waiting.";
        }

        $primaryInsight = $operationsAdvisorInsights[0] ?? null;

        if ($primaryInsight !== null) {
            return $primaryInsight->recommendation;
        }

        $explanation = trim((string) ($response->recommendationExplanation ?? ''));

        return $explanation !== ''
            ? $explanation
            : 'This case can proceed with standard service handling once the next dependency is cleared.';
    }

    /**
     * @param  list<OperationsInsightDTO>  $operationsAdvisorInsights
     */
    private function buildRecommendation(
        AIResponseDTO $response,
        \App\Data\AI\AIContextDTO $context,
        array $operationsAdvisorInsights,
    ): string {
        if ($context->serialMissing) {
            $parts = ['Request the serial immediately'];

            if ($this->warrantyNeedsVerification($context, $response)) {
                $parts[] = 'verify warranty once received';
            }

            if ($this->hasHighSlaRisk($context, $operationsAdvisorInsights)) {
                $parts[] = 'proactively update the customer regarding SLA';
            } else {
                $parts[] = 'confirm next steps with the customer';
            }

            return $this->joinRecommendationParts($parts).'.';
        }

        if ($context->isWarrantyExpired()) {
            return 'Confirm chargeable repair expectations with the customer before engineering begins work.';
        }

        if ($context->customerIntelligence->repeatIssueDetected) {
            return 'Review prior technician notes, assign senior support if needed, and communicate a proactive repair plan.';
        }

        if ($this->hasHighSlaRisk($context, $operationsAdvisorInsights)) {
            return 'Prioritize resolution, assign ownership, and send the customer an immediate status update.';
        }

        $primaryAction = $response->suggestedNextActions[0] ?? null;

        if ($primaryAction !== null) {
            $description = trim($primaryAction->description);

            return $description !== ''
                ? $primaryAction->title.': '.$description.'.'
                : $primaryAction->title.'.';
        }

        $advisorRecommendation = $operationsAdvisorInsights[0]?->recommendation;

        return filled($advisorRecommendation)
            ? $advisorRecommendation
            : 'Review incident details and contact the customer with the next update.';
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
     * @param  list<string>  $parts
     */
    private function joinRecommendationParts(array $parts): string
    {
        if ($parts === []) {
            return '';
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }
}
