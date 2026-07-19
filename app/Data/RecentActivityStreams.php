<?php

namespace App\Data;

use Illuminate\Support\Collection;

readonly class RecentActivityStreams
{
    public function __construct(
        public Collection $customer,
        public Collection $team,
        public Collection $ira,
        public bool $showIra,
    ) {}

    public static function empty(): self
    {
        return new self(collect(), collect(), collect(), false);
    }

    public function isEmpty(): bool
    {
        if ($this->customer->isNotEmpty() || $this->team->isNotEmpty()) {
            return false;
        }

        return ! $this->showIra || $this->ira->isEmpty();
    }

    /**
     * @return list<array{key: string, label: string, threads: Collection<int, RecentActivityThread>, count: int, collapsed_default: bool}>
     */
    public function sections(): array
    {
        $streamConfig = config('dashboard-activity.streams', []);
        $sections = [
            [
                'key' => 'customer',
                'label' => (string) ($streamConfig['customer']['label'] ?? 'Customer Activity'),
                'threads' => $this->customer,
                'count' => $this->countItems($this->customer),
                'collapsed_default' => (bool) ($streamConfig['customer']['collapsed_default'] ?? false),
            ],
            [
                'key' => 'team',
                'label' => (string) ($streamConfig['team']['label'] ?? 'Team Activity'),
                'threads' => $this->team,
                'count' => $this->countItems($this->team),
                'collapsed_default' => (bool) ($streamConfig['team']['collapsed_default'] ?? false),
            ],
        ];

        if ($this->showIra) {
            $sections[] = [
                'key' => 'ira',
                'label' => (string) ($streamConfig['ira']['label'] ?? 'IRA Activity'),
                'threads' => $this->ira,
                'count' => $this->countItems($this->ira),
                'collapsed_default' => (bool) ($streamConfig['ira']['collapsed_default'] ?? true),
            ];
        }

        return array_values(array_filter(
            $sections,
            fn (array $section): bool => $section['threads']->isNotEmpty(),
        ));
    }

    /**
     * @param  Collection<int, RecentActivityThread>  $threads
     */
    private function countItems(Collection $threads): int
    {
        return (int) $threads->sum(fn (RecentActivityThread $thread): int => $thread->count());
    }
}
