<?php

namespace App\Services\Platform\Cards;

use App\Contracts\Platform\PlatformCardProvider;
use App\Data\Platform\PlatformCardDefinition;
use App\Data\Platform\PlatformCardPayload;
use App\Enums\PlatformCardSize;
use App\Enums\PlatformHealthStatus;
use App\Models\User;
use App\Services\Platform\Concerns\InteractsWithPlatformCardDefinition;

class PlaceholderSectionCardProvider implements PlatformCardProvider
{
    use InteractsWithPlatformCardDefinition;

    /**
     * @param  list<string>  $upcomingCards
     */
    public function __construct(
        private readonly string $sectionId,
        private readonly string $cardTitle,
        private readonly int $priority,
        private readonly array $upcomingCards,
        private readonly ?string $icon = null,
    ) {}

    public function definition(): PlatformCardDefinition
    {
        return new PlatformCardDefinition(
            id: 'placeholder_'.$this->sectionId,
            title: $this->cardTitle,
            section: $this->sectionId,
            priority: $this->priority,
            icon: $this->icon,
            refreshable: false,
            expandable: false,
            permission: null,
            size: PlatformCardSize::Full,
            subtitle: 'Cards coming next',
            bodyPartial: 'admin.platform.cards.placeholder-section',
            estimatedRefreshCost: 'cheap',
        );
    }

    public function load(User $viewer): PlatformCardPayload
    {
        $definition = $this->definition();

        return PlatformCardPayload::fromDefinition(
            definition: $definition,
            status: PlatformHealthStatus::Disabled,
            generatedAt: now(),
            meta: [
                'upcoming_cards' => $this->upcomingCards,
                'message' => 'Cards coming next',
            ],
        );
    }
}
