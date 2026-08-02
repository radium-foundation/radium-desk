<?php

namespace App\Data\Platform;

use App\Enums\PlatformZoneId;

readonly class PlatformZoneDefinition
{
    public function __construct(
        public PlatformZoneId $id,
        public string $title,
        public int $refreshPriority,
        public int $sortOrder,
        public string $icon,
        public bool $expandable = false,
        public ?string $permission = null,
        public ?string $description = null,
    ) {}

    public function key(): string
    {
        return $this->id->value;
    }

    public function domId(): string
    {
        return $this->id->domId();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'title' => $this->title,
            'refresh_priority' => $this->refreshPriority,
            'sort_order' => $this->sortOrder,
            'icon' => $this->icon,
            'expandable' => $this->expandable,
            'permission' => $this->permission,
            'description' => $this->description,
            'dom_id' => $this->domId(),
        ];
    }
}
