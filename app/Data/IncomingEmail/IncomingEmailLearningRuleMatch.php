<?php

namespace App\Data\IncomingEmail;

use App\Models\IncomingEmailLearningRule;

final readonly class IncomingEmailLearningRuleMatch
{
    public function __construct(
        public IncomingEmailLearningRule $rule,
        public string $matchedOn,
        public string $matchedValue,
    ) {}
}
