<?php

namespace App\Data\AI;

use App\Data\Knowledge\KnowledgeResponseDTO;
use App\Services\AI\CustomerScopeQueryCache;

readonly class AIIncidentBundle
{
    public function __construct(
        public AIResponseDTO $response,
        public AIContextDTO $context,
        public KnowledgeResponseDTO $knowledge,
        public CustomerScopeQueryCache $scopeCache,
    ) {}
}
