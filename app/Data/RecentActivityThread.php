<?php

namespace App\Data;

readonly class RecentActivityThread
{
    /**
     * @param  list<RecentActivityItem>  $items
     */
    public function __construct(
        public ?int $incidentId,
        public ?string $incidentReference,
        public array $items,
    ) {}

    public function count(): int
    {
        return count($this->items);
    }

    public function isCollapsible(): bool
    {
        return $this->incidentId !== null && $this->count() > 1;
    }

    public function latest(): ?RecentActivityItem
    {
        return $this->items[0] ?? null;
    }
}
