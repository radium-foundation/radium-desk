<?php

namespace App\Support\Customer360;

use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;
use App\Services\Customer360\Intelligence\Builders\CaseAdvisorDecisionBuilder;

/**
 * Thin IRA Advisor presenter.
 *
 * Legacy present() delegates decisions to CaseAdvisorDecisionBuilder.
 * Engine path uses presentFromSnapshot() and never re-queries domain services.
 */
class Customer360IraAdvisorPresenter
{
    public function __construct(
        private readonly CaseAdvisorDecisionBuilder $decisionBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function present(array $context): ?array
    {
        return $this->decisionBuilder->decide($context);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function presentFromSnapshot(CaseIntelligenceSnapshot $snapshot): ?array
    {
        return $snapshot->advisorViewModel;
    }
}
