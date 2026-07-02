<?php

namespace App\Contracts\AI;

use App\Data\AI\AIContextDTO;
use App\Data\AI\AIRecommendationDTO;

interface AIProvider
{
    public function name(): string;

    public function summarizeIncident(AIContextDTO $context): string;

    public function suggestReply(AIContextDTO $context): string;

    /**
     * @return list<AIRecommendationDTO>
     */
    public function suggestNextActions(AIContextDTO $context): array;

    public function classifyIncident(AIContextDTO $context): string;

    public function estimateResolution(AIContextDTO $context): string;

    public function explainRecommendation(AIContextDTO $context, string $recommendation): string;
}
