<?php

namespace App\Services\Platform\Cards;

use App\Contracts\Platform\PlatformCardProvider;
use App\Data\Platform\PlatformCardDefinition;
use App\Data\Platform\PlatformCardPayload;
use App\Data\Platform\PlatformHealthComponent;
use App\Data\Platform\PlatformMetric;
use App\Enums\PlatformCardSize;
use App\Enums\PlatformDashboardSection;
use App\Models\User;
use App\Services\Platform\Concerns\InteractsWithPlatformCardDefinition;
use App\Services\Platform\Health\PlatformHealthSnapshotService;

class PlatformHealthCardProvider implements PlatformCardProvider
{
    use InteractsWithPlatformCardDefinition;

    public function __construct(
        private readonly PlatformHealthSnapshotService $healthSnapshot,
    ) {}

    public function definition(): PlatformCardDefinition
    {
        return new PlatformCardDefinition(
            id: 'platform_health',
            title: 'Platform Health',
            section: PlatformDashboardSection::PlatformHealth->value,
            priority: 10,
            icon: 'bi-heart-pulse',
            refreshable: true,
            expandable: false,
            permission: null,
            size: PlatformCardSize::Large,
            bodyPartial: 'admin.platform.cards.platform-health',
            estimatedRefreshCost: 'cheap',
        );
    }

    public function load(User $viewer): PlatformCardPayload
    {
        $definition = $this->definition();
        $snapshot = $this->healthSnapshot->probe();

        $metrics = array_map(
            fn (PlatformHealthComponent $component): PlatformMetric => new PlatformMetric(
                key: $component->key,
                label: $component->label,
                value: $component->status->label(),
                detail: $component->detail,
                status: $component->status,
            ),
            $snapshot->components,
        );

        return PlatformCardPayload::fromDefinition(
            definition: $definition,
            status: $snapshot->status,
            generatedAt: $snapshot->generatedAt,
            metrics: $metrics,
            meta: [
                'components' => array_map(
                    fn (PlatformHealthComponent $component): array => $component->toArray(),
                    $snapshot->components,
                ),
            ],
        );
    }
}
