<?php

namespace App\Services\Platform\Cards;

use App\Contracts\Platform\PlatformCardProvider;
use App\Data\Platform\PlatformCardDefinition;
use App\Data\Platform\PlatformCardPayload;
use App\Enums\PlatformCardSize;
use App\Enums\PlatformHealthStatus;
use App\Models\User;
use App\Services\Platform\Concerns\InteractsWithPlatformCardDefinition;
use Illuminate\Support\Facades\Route;

/**
 * Section card that deep-links into existing platform workspaces.
 *
 * S1: replaces inert "coming next" placeholders with reachable destinations
 * (MC-18–MC-24) without new pages or KPI implementations.
 */
class PlaceholderSectionCardProvider implements PlatformCardProvider
{
    use InteractsWithPlatformCardDefinition;

    /**
     * @param  list<array{
     *     label: string,
     *     route: string,
     *     params?: array<string, mixed>,
     *     fragment?: string,
     *     description?: string
     * }>  $workspaceLinks
     * @param  array{route: string, params?: array<string, mixed>, fragment?: string}|null  $detailRoute
     * @param  list<string>  $upcomingCards
     */
    public function __construct(
        private readonly string $sectionId,
        private readonly string $cardTitle,
        private readonly int $priority,
        private readonly array $workspaceLinks,
        private readonly ?array $detailRoute = null,
        private readonly ?string $permission = null,
        private readonly array $upcomingCards = [],
        private readonly ?string $icon = null,
        private readonly string $message = 'Open the existing workspace for this area.',
    ) {}

    public function definition(): PlatformCardDefinition
    {
        $links = $this->resolvedWorkspaceLinks();
        $actions = array_map(
            static fn (array $link): array => [
                'label' => $link['label'],
                'url' => $link['url'],
            ],
            $links,
        );

        return new PlatformCardDefinition(
            id: 'placeholder_'.$this->sectionId,
            title: $this->cardTitle,
            section: $this->sectionId,
            priority: $this->priority,
            icon: $this->icon,
            refreshable: false,
            expandable: false,
            permission: $this->permission,
            size: PlatformCardSize::Full,
            subtitle: null,
            bodyPartial: 'admin.platform.cards.placeholder-section',
            detailUrl: $this->resolvedDetailUrl($links),
            actions: $actions,
            estimatedRefreshCost: 'cheap',
        );
    }

    public function load(User $viewer): PlatformCardPayload
    {
        $definition = $this->definition();
        $links = $this->resolvedWorkspaceLinks();

        return PlatformCardPayload::fromDefinition(
            definition: $definition,
            status: $links === [] ? PlatformHealthStatus::Disabled : PlatformHealthStatus::Healthy,
            generatedAt: now(),
            meta: [
                'workspace_links' => $links,
                'upcoming_cards' => $this->upcomingCards,
                'message' => $this->message,
            ],
            detailUrl: $definition->detailUrl,
        );
    }

    /**
     * @return list<array{label: string, url: string, description?: string}>
     */
    private function resolvedWorkspaceLinks(): array
    {
        $links = [];

        foreach ($this->workspaceLinks as $link) {
            $url = $this->resolveRouteUrl(
                routeName: (string) ($link['route'] ?? ''),
                params: is_array($link['params'] ?? null) ? $link['params'] : [],
                fragment: isset($link['fragment']) ? (string) $link['fragment'] : null,
            );

            if ($url === null || blank($link['label'] ?? null)) {
                continue;
            }

            $resolved = [
                'label' => (string) $link['label'],
                'url' => $url,
            ];

            if (! empty($link['description'])) {
                $resolved['description'] = (string) $link['description'];
            }

            $links[] = $resolved;
        }

        return $links;
    }

    /**
     * @param  list<array{label: string, url: string, description?: string}>  $links
     */
    private function resolvedDetailUrl(array $links): ?string
    {
        if ($this->detailRoute !== null) {
            return $this->resolveRouteUrl(
                routeName: (string) ($this->detailRoute['route'] ?? ''),
                params: is_array($this->detailRoute['params'] ?? null) ? $this->detailRoute['params'] : [],
                fragment: isset($this->detailRoute['fragment']) ? (string) $this->detailRoute['fragment'] : null,
            );
        }

        return $links[0]['url'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function resolveRouteUrl(string $routeName, array $params = [], ?string $fragment = null): ?string
    {
        if ($routeName === '' || ! Route::has($routeName)) {
            return null;
        }

        $url = route($routeName, $params);

        if ($fragment !== null && $fragment !== '') {
            $url .= '#'.$fragment;
        }

        return $url;
    }
}
