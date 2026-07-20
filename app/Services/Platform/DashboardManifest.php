<?php

namespace App\Services\Platform;

use App\Contracts\Platform\PlatformCardProvider;
use App\Data\Platform\PlatformCardPayload;
use App\Data\Platform\PlatformDashboardData;
use App\Data\Platform\PlatformSectionDefinition;
use App\Models\User;

class DashboardManifest
{
    public function __construct(
        private readonly PlatformSectionRegistry $sectionRegistry,
        private readonly PlatformCardRegistry $cardRegistry,
    ) {}

    public function registerSection(PlatformSectionDefinition $section): void
    {
        $this->sectionRegistry->register($section);
    }

    public function registerCard(PlatformCardProvider $card): void
    {
        $this->cardRegistry->register($card);
    }

    /**
     * @return list<PlatformSectionDefinition>
     */
    public function sections(): array
    {
        return $this->sectionRegistry->ordered();
    }

    public function resolve(User $viewer): PlatformDashboardData
    {
        $cardsBySection = [];

        foreach ($this->cardRegistry->all() as $provider) {
            $definition = $provider->definition();

            if ($definition->hidden) {
                continue;
            }

            if (! $provider->authorize($viewer)) {
                continue;
            }

            $cardsBySection[$definition->section][] = $provider->load($viewer);
        }

        foreach ($cardsBySection as $sectionId => $cards) {
            usort(
                $cards,
                function (PlatformCardPayload $a, PlatformCardPayload $b): int {
                    if ($a->pinned !== $b->pinned) {
                        return $b->pinned <=> $a->pinned;
                    }

                    return $a->sortKey <=> $b->sortKey;
                },
            );
            $cardsBySection[$sectionId] = $cards;
        }

        $sections = [];

        foreach ($this->sectionRegistry->ordered() as $section) {
            if ($section->permission !== null && ! $viewer->can($section->permission)) {
                continue;
            }

            $cards = $cardsBySection[$section->id] ?? [];

            if ($cards === []) {
                continue;
            }

            $sections[] = [
                'key' => $section->id,
                'label' => $section->title,
                'icon' => $section->icon,
                'priority' => $section->priority,
                'description' => $section->description,
                'collapsible' => $section->collapsible,
                'cards' => $cards,
            ];
        }

        return new PlatformDashboardData(
            sections: $sections,
            generatedAt: now(),
        );
    }

    public function cardPayload(User $viewer, string $cardKey): PlatformCardPayload
    {
        $provider = $this->cardRegistry->get($cardKey);

        abort_unless($provider->authorize($viewer), 403);

        return $provider->refresh($viewer);
    }
}
