<?php

namespace App\Data\Platform;

use App\Enums\PlatformCardSize;

readonly class PlatformCardDefinition
{
    /**
     * @param  list<array{label: string, url: string}>  $actions
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $section,
        public int $priority,
        public ?string $icon = null,
        public bool $refreshable = true,
        public bool $expandable = false,
        public ?string $permission = null,
        public PlatformCardSize $size = PlatformCardSize::Large,
        public ?string $subtitle = null,
        public ?string $badge = null,
        public ?string $bodyPartial = null,
        public ?string $detailUrl = null,
        public array $actions = [],
        public ?string $estimatedRefreshCost = null,
        public ?int $order = null,
        public bool $pinned = false,
        public bool $hidden = false,
        public bool $favorite = false,
    ) {}

    public function sortKey(): int
    {
        return $this->order ?? $this->priority;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'section' => $this->section,
            'priority' => $this->priority,
            'icon' => $this->icon,
            'refreshable' => $this->refreshable,
            'expandable' => $this->expandable,
            'permission' => $this->permission,
            'size' => $this->size->value,
            'subtitle' => $this->subtitle,
            'badge' => $this->badge,
            'body_partial' => $this->bodyPartial,
            'detail_url' => $this->detailUrl,
            'actions' => $this->actions,
            'estimated_refresh_cost' => $this->estimatedRefreshCost,
            'order' => $this->order,
            'pinned' => $this->pinned,
            'hidden' => $this->hidden,
            'favorite' => $this->favorite,
        ];
    }
}
