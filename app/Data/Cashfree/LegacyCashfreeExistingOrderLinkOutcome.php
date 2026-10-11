<?php

namespace App\Data\Cashfree;

final readonly class LegacyCashfreeExistingOrderLinkOutcome
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public bool $success,
        public bool $alreadyLinked,
        public string $reason,
        public array $details = [],
    ) {}
}
