<?php

namespace App\Data\Platform;

readonly class PlatformSectionDefinition
{
    public function __construct(
        public string $id,
        public string $title,
        public int $priority,
        public ?string $icon = null,
        public ?string $permission = null,
        public ?string $description = null,
        public bool $collapsible = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'priority' => $this->priority,
            'icon' => $this->icon,
            'permission' => $this->permission,
            'description' => $this->description,
            'collapsible' => $this->collapsible,
        ];
    }
}
