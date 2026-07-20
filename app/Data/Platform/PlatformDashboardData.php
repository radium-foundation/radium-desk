<?php

namespace App\Data\Platform;

use Illuminate\Support\Carbon;

readonly class PlatformDashboardData
{
    /**
     * @param  list<array{
     *     key: string,
     *     label: string,
     *     icon: string|null,
     *     priority: int,
     *     description: string|null,
     *     collapsible: bool,
     *     cards: list<PlatformCardPayload>
     * }>  $sections
     */
    public function __construct(
        public array $sections,
        public Carbon $generatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sections' => array_map(
                fn (array $section): array => [
                    'key' => $section['key'],
                    'label' => $section['label'],
                    'icon' => $section['icon'] ?? null,
                    'priority' => $section['priority'] ?? 0,
                    'description' => $section['description'] ?? null,
                    'collapsible' => $section['collapsible'] ?? false,
                    'cards' => array_map(
                        fn (PlatformCardPayload $card): array => $card->toArray(),
                        $section['cards'],
                    ),
                ],
                $this->sections,
            ),
            'generated_at' => $this->generatedAt->toIso8601String(),
        ];
    }
}
