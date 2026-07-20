<?php

namespace App\Data;

readonly class SerialWaitingRepairSummary
{
    /**
     * @param  list<array<string, mixed>>  $samples
     */
    public function __construct(
        public bool $dryRun,
        public int $scanned,
        public int $repaired,
        public int $skipped,
        public array $samples = [],
        public ?string $configurationError = null,
    ) {}
}
