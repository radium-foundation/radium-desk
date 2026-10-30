<?php

namespace App\Data\StatutoryCoverage;

final readonly class StatutoryCoverageChannelReport
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<StatutoryCoverageOrderRow>  $exceptions
     */
    public function __construct(
        public string $channel,
        public string $channelLabel,
        public StatutoryCoverageWindow $window,
        public StatutoryCoverageSourcePopulation $sourcePopulation,
        public int $deskPopulationCount,
        public int $deskPopulationTotal,
        public bool $deskPopulationComplete,
        public bool $sourcePopulationComplete,
        public array $counts,
        public array $exceptions,
        public bool $reportComplete,
        public ?string $partialReason,
    ) {}

    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'channel_label' => $this->channelLabel,
            'window' => $this->window->toArray(),
            'source_population' => $this->sourcePopulation->toArray(),
            'desk_population_count' => $this->deskPopulationCount,
            'desk_population_total' => $this->deskPopulationTotal,
            'desk_population_complete' => $this->deskPopulationComplete,
            'source_population_complete' => $this->sourcePopulationComplete,
            'counts_by_classification' => $this->counts,
            'exceptions' => array_map(fn (StatutoryCoverageOrderRow $row) => $row->toArray(), $this->exceptions),
            'report_complete' => $this->reportComplete,
            'partial_reason' => $this->partialReason,
        ];
    }
}
