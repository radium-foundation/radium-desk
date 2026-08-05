<?php

namespace App\Data\IncomingEmail;

readonly class IncomingEmailPriorityMatch
{
    public function __construct(
        public string $matchedPhrase,
        public string $ruleSource,
    ) {}
}
