<?php

namespace App\Data;

use Illuminate\Support\Collection;

readonly class RecentActivityStreams
{
    public function __construct(
        public Collection $customer,
        public Collection $agentAdmin,
        public Collection $system,
        public bool $showSystem,
    ) {}

    public static function empty(): self
    {
        return new self(collect(), collect(), collect(), false);
    }

    public function isEmpty(): bool
    {
        if ($this->customer->isNotEmpty() || $this->agentAdmin->isNotEmpty()) {
            return false;
        }

        return ! $this->showSystem || $this->system->isEmpty();
    }

    /**
     * @return list<array{key: string, label: string, items: Collection<int, RecentActivityItem>, collapsed_default: bool}>
     */
    public function sections(): array
    {
        $streamConfig = config('dashboard-activity.streams', []);
        $sections = [
            [
                'key' => 'customer',
                'label' => (string) ($streamConfig['customer']['label'] ?? 'Customer Activity'),
                'items' => $this->customer,
                'collapsed_default' => (bool) ($streamConfig['customer']['collapsed_default'] ?? false),
            ],
            [
                'key' => 'agent_admin',
                'label' => (string) ($streamConfig['agent_admin']['label'] ?? 'Agent & Admin Activity'),
                'items' => $this->agentAdmin,
                'collapsed_default' => (bool) ($streamConfig['agent_admin']['collapsed_default'] ?? false),
            ],
        ];

        if ($this->showSystem) {
            $sections[] = [
                'key' => 'system',
                'label' => (string) ($streamConfig['system']['label'] ?? 'System Activity'),
                'items' => $this->system,
                'collapsed_default' => (bool) ($streamConfig['system']['collapsed_default'] ?? true),
            ];
        }

        return array_values(array_filter(
            $sections,
            fn (array $section): bool => $section['items']->isNotEmpty(),
        ));
    }
}
