<?php

namespace App\Services\Customer360\Intelligence\Builders;

use App\Data\AI\AIIncidentBundle;
use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceFacts;
use App\Data\Operations\OperationsInsightDTO;
use App\Models\Incident;
use App\Services\AI\IRAExecutiveSummaryService;

/**
 * Wraps the existing deterministic executive summary service.
 */
class CaseSummaryBuilder
{
    public function __construct(
        private readonly IRAExecutiveSummaryService $executiveSummaryService,
    ) {}

    /**
     * @param  list<OperationsInsightDTO>  $operationsAdvisorInsights
     */
    public function build(
        Incident $incident,
        AIIncidentBundle $bundle,
        CaseIntelligenceFacts $facts,
        array $operationsAdvisorInsights,
    ): IRAExecutiveSummaryDTO {
        return $this->executiveSummaryService->buildFromBundle(
            incident: $incident,
            bundle: $bundle,
            snapshot: $facts->buildSnapshot,
            operationsAdvisorInsights: $operationsAdvisorInsights,
        );
    }
}
