<?php

namespace App\Data\StatutoryCoverage;

use Illuminate\Support\Carbon;

final readonly class StatutoryCoverageReport
{
    /**
     * @param  list<StatutoryCoverageChannelReport>  $channels
     * @param  list<string>  $watchdogSuggestions
     */
    public function __construct(
        public Carbon $generatedAt,
        public bool $dryRun,
        public array $channels,
        public array $watchdogSuggestions,
    ) {}

    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt->toIso8601String(),
            'dry_run' => $this->dryRun,
            'channels' => array_map(fn (StatutoryCoverageChannelReport $c) => $c->toArray(), $this->channels),
            'watchdog_alert_suggestions' => $this->watchdogSuggestions,
        ];
    }
}
